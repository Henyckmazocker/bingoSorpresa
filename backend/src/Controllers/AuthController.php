<?php
namespace App\Controllers;

use App\Domain\UseCases\Auth\LoginUserUseCase;
use App\Infrastructure\Session\SessionManager;
use App\Infrastructure\Middleware\AuthMiddleware;
use App\Infrastructure\Auth\GoogleOAuthVerifier;
use App\Infrastructure\Auth\JWTService;
use App\Domain\Services\EmailWhitelist;
use App\Domain\Repository\User\UserRepositoryInterface;
use Psr\Log\LoggerInterface;

class AuthController extends BaseController implements Contracts\AuthControllerInterface
{
    private LoginUserUseCase $loginUserUseCase;
    private SessionManager $sessionManager;
    private AuthMiddleware $authMiddleware;
    private GoogleOAuthVerifier $googleVerifier;
    private JWTService $jwtService;
    private EmailWhitelist $whitelist;
    private UserRepositoryInterface $users;
    private ?LoggerInterface $logger;

    /** Texto que hay que teclear para borrar la cuenta (el frontend lo pide en el ConfirmDialog). */
    public const DELETE_CONFIRMATION = 'BORRAR';

    public function __construct(
        LoginUserUseCase $loginUserUseCase,
        SessionManager $sessionManager,
        AuthMiddleware $authMiddleware,
        GoogleOAuthVerifier $googleVerifier,
        JWTService $jwtService,
        EmailWhitelist $whitelist,
        UserRepositoryInterface $users,
        ?LoggerInterface $logger = null
    ) {
        $this->loginUserUseCase = $loginUserUseCase;
        $this->sessionManager = $sessionManager;
        $this->authMiddleware = $authMiddleware;
        $this->googleVerifier = $googleVerifier;
        $this->jwtService = $jwtService;
        $this->whitelist = $whitelist;
        $this->users = $users;
        $this->logger = $logger;
    }

    public function login(array $inputData): array
    {
        if (!isset($inputData['google_token']) || !is_string($inputData['google_token'])) {
            throw new \InvalidArgumentException('Google token is required for login.');
        }
        
        // Properly verify Google ID token with cryptographic signature validation
        $payload = $this->googleVerifier->verifyToken($inputData['google_token']);

        // Whitelist gate: only allowed emails may log in (no user/session created otherwise).
        if (!$this->whitelist->isAllowed($payload['email'] ?? null)) {
            return $this->errorResponse('Esta cuenta no está autorizada para acceder.', 403);
        }

        // Create login command from verified payload
        $command = \App\Domain\DTO\Commands\LoginUserCommand::fromGoogleToken($payload);
        $user = $this->loginUserUseCase->execute($command);

        // Cuenta dada de baja (user_set_active.php --active=0): ni sesión ni JWT.
        if (!$user->isActive()) {
            return $this->errorResponse('Esta cuenta está desactivada.', 403);
        }

        $this->sessionManager->login($user);
        
        $jwtToken = $this->jwtService->generate([
            'user_id' => $user->getId(),
            'email'   => $user->getEmail()->toString(),
            'name'    => $user->getName(),
            'picture' => $user->getPicture(),
        ]);

        return $this->successResponse('Login successful.', [
            'user'       => $user->toArray(),
            'csrf_token' => $this->authMiddleware->getCSRFToken(),
            'jwt_token'  => $jwtToken,
        ]);
    }

    public function logout(): array
    {
        $this->sessionManager->logout();
        return $this->successResponse('Logout successful.');
    }

    public function checkAuth(): array
    {
        $authResult = $this->authMiddleware->requireAuth();
        if ($authResult['status'] === 'error') {
            return $authResult;
        } else {
            return $this->successResponse('User is authenticated.', [
                'user' => $authResult['user'],
                'csrf_token' => $this->authMiddleware->getCSRFToken()
            ]);
        }
    }

    /**
     * Cheap authenticated endpoint: devuelve el usuario de la petición (resuelto por
     * AuthenticationMiddleware desde sesión O JWT). El frontend lo usa al arrancar para validar un
     * JWT guardado y pintar quién está dentro sin depender de ninguna acción de dominio.
     */
    public function me(?int $userId): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        $user = $this->users->findById($userId);
        if ($user === null) {
            return $this->errorResponse('Authentication required.', 401);
        }
        return $this->successResponse('Authenticated', ['user_id' => $userId, 'user' => $user->toArray()]);
    }

    /**
     * Borra la cuenta: la fila de `users` (el CASCADE se lleva uploads, bingos, items, tiradas y
     * emparejados) y después `storage/uploads/<id>/`. Primero la fila: si fallase el disco quedan
     * ficheros huérfanos sin dueño, nunca una cuenta viva con las fotos rotas.
     */
    public function deleteAccount(?int $userId, array $inputData): array
    {
        if (!$userId) {
            return $this->errorResponse('Authentication required.', 401);
        }
        if (($inputData['confirm'] ?? null) !== self::DELETE_CONFIRMATION) {
            return $this->errorResponse('Para borrar la cuenta escribe ' . self::DELETE_CONFIRMATION . '.', 400);
        }

        $this->users->delete($userId);
        $this->removeUserUploads($userId);
        $this->sessionManager->logout();

        $this->logger?->info('Account deleted', ['user_id' => $userId]);

        return [
            'status' => 'success',
            'message' => 'Cuenta borrada.',
            'data' => new \stdClass(), // {} y no []: contrato de la API
            'http_code' => 200,
        ];
    }

    /** Raíz de las imágenes subidas (volumen `bingo_uploads_prod` en producción). */
    private function uploadsRoot(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    private function removeUserUploads(int $userId): void
    {
        $dir = $this->uploadsRoot() . '/' . $userId;
        if (!is_dir($dir)) {
            return;
        }
        $failed = [];
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $path = $item->getPathname();
                // Nada de seguir enlaces: se borra el enlace, no lo que apunta. Sin «@», un fallo
                // de permisos saldría como warning HTML dentro de la respuesta JSON.
                $ok = ($item->isDir() && !$item->isLink()) ? @rmdir($path) : @unlink($path);
                if (!$ok) {
                    $failed[] = $path;
                }
            }
            if (!@rmdir($dir)) {
                $failed[] = $dir;
            }
        } catch (\Throwable $e) {
            $failed[] = $dir . ' (' . $e->getMessage() . ')';
        }
        if ($failed) {
            // La cuenta ya no existe: lo que quede es basura sin dueño, se avisa y se sigue.
            $this->logger?->error('Failed to remove user uploads', [
                'user_id' => $userId,
                'paths' => array_slice($failed, 0, 20),
            ]);
        }
    }
}
