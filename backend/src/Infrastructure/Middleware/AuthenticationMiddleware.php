<?php

declare(strict_types=1);

namespace App\Infrastructure\Middleware;

use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\Services\EmailWhitelist;
use App\Infrastructure\Auth\JWTService;
use Psr\Log\LoggerInterface;

/**
 * Authentication Middleware
 * Verifies that user is authenticated before proceeding.
 * Supports two methods, in this order:
 *   1. Authorization: Bearer <jwt> header (web, mobile / Capacitor / dev token). Si viene, decide
 *      él: un Bearer inválido es 401 aunque haya cookie de sesión.
 *   2. PHP session cookie (solo si no hay Bearer)
 *
 * Also enforces, on EVERY authenticated request (session AND JWT), that the account still exists
 * (401 if deleted), is active (403 if users.is_active = 0) and passes the login whitelist (403), so
 * a token for such an account — including a hand-minted dev JWT — is rejected.
 */
class AuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly JWTService $jwtService,
        private readonly UserRepositoryInterface $users,
        private readonly EmailWhitelist $whitelist
    ) {}

    public function handle(array $request, callable $next): array
    {
        $userId = null;
        $authMethod = null;
        $email = null;

        // --- 1. JWT-based auth (web, mobile / Capacitor / dev) ---
        // Apache may pass the header as HTTP_AUTHORIZATION, REDIRECT_HTTP_AUTHORIZATION,
        // or via getallheaders() depending on the PHP SAPI / mod_rewrite config.
        $authHeader = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (empty($authHeader) && function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            $authHeader = $headers['authorization'] ?? '';
        }
        if (str_starts_with($authHeader, 'Bearer ')) {
            // Si viene Bearer, decide él: el JWT manda sobre la cookie de sesión. Si no vale, 401
            // sin recurrir a la sesión (si no, el front seguiría con un JWT podrido y sin CSRF).
            $payload = $this->jwtService->validate(substr($authHeader, 7));
            if ($payload === null || !isset($payload['user_id'])) {
                $this->logger->warning('Authentication failed - Invalid bearer token', [
                    'action' => $request['action'] ?? 'unknown',
                    'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ]);
                return ['status' => 'error', 'message' => 'Authentication required. Please log in.', 'http_code' => 401];
            }
            $userId = (int) $payload['user_id'];
            $authMethod = 'jwt';
            $email = $payload['email'] ?? null;
        } elseif (isset($_SESSION['user_data']['id'])) {
            // --- 2. Session-based auth (solo cookie, sin Bearer): pasa por CSRF ---
            $userId = (int) $_SESSION['user_data']['id'];
            $authMethod = 'session';
            $email = $_SESSION['user_data']['email'] ?? null;
        }

        if ($userId === null) {
            $this->logger->warning('Authentication failed - No user session', [
                'action' => $request['action'] ?? 'unknown',
                'ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
            return ['status' => 'error', 'message' => 'Authentication required. Please log in.', 'http_code' => 401];
        }

        // --- Cuenta viva y activa (session + JWT). Un JWT sigue siendo válido tras borrar la
        //     cuenta o darla de baja (user_set_active.php --active=0): se mira la fila cada vez. ---
        $user = $this->users->findById($userId);
        if ($user === null) {
            $this->logger->warning('Authenticated user no longer exists', [
                'user_id' => $userId,
                'auth_method' => $authMethod,
                'action' => $request['action'] ?? 'unknown',
            ]);
            return ['status' => 'error', 'message' => 'Authentication required. Please log in.', 'http_code' => 401];
        }
        if (!$user->isActive()) {
            $this->logger->warning('Blocked inactive account', [
                'user_id' => $userId,
                'auth_method' => $authMethod,
                'action' => $request['action'] ?? 'unknown',
            ]);
            return ['status' => 'error', 'message' => 'Esta cuenta está desactivada.', 'http_code' => 403];
        }

        // --- Whitelist gate (session + JWT). Dev tokens carry no email → se usa el de la fila. ---
        if ($this->whitelist->isRestricted()) {
            if ($email === null) {
                $email = $user->getEmail()->toString();
            }
            if (!$this->whitelist->isAllowed($email)) {
                $this->logger->warning('Blocked non-whitelisted account', [
                    'user_id' => $userId,
                    'auth_method' => $authMethod,
                    'action' => $request['action'] ?? 'unknown',
                ]);
                return ['status' => 'error', 'message' => 'Esta cuenta no está autorizada para acceder.', 'http_code' => 403];
            }
        }

        $request['user_id'] = $userId;
        $request['auth_method'] = $authMethod;
        if ($authMethod === 'jwt') {
            $this->logger->debug('User authenticated via JWT', [
                'user_id' => $userId, 'action' => $request['action'] ?? 'unknown',
            ]);
        }
        return $next($request);
    }
}
