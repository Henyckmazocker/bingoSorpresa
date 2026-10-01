<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\Services\EmailWhitelist;
use App\Domain\UseCases\Auth\LoginUserUseCase;
use App\Infrastructure\Auth\GoogleOAuthVerifier;
use App\Infrastructure\Auth\JWTService;
use App\Infrastructure\Middleware\AuthenticationMiddleware;
use App\Infrastructure\Middleware\AuthMiddleware;
use App\Infrastructure\Session\SessionManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * M2: `delete_account` exige {confirm:"BORRAR"} y AuthenticationMiddleware corta las cuentas
 * borradas (401) o desactivadas (403) aunque su JWT siga siendo válido.
 */
class AccountStatusTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['ALLOWED_EMAILS'] = '';
        $_ENV['JWT_SECRET'] ??= 'test-secret-de-al-menos-32-caracteres!!';
        unset($_SESSION['user_data']);
    }

    private function user(bool $active): User
    {
        return new User(
            7,
            GoogleId::fromString('100000000000000000007'),
            Email::fromString('test@example.com'),
            'Test',
            null,
            null,
            null,
            null,
            $active
        );
    }

    private function controller(UserRepositoryInterface $users): AuthController
    {
        return new AuthController(
            $this->createMock(LoginUserUseCase::class),
            $this->createMock(SessionManager::class),
            $this->createMock(AuthMiddleware::class),
            $this->createMock(GoogleOAuthVerifier::class),
            $this->createMock(JWTService::class),
            new EmailWhitelist(),
            $users
        );
    }

    public function test_delete_account_without_confirm_is_400_and_deletes_nothing(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects($this->never())->method('delete');

        $controller = $this->controller($users);
        foreach ([[], ['confirm' => 'borrar'], ['confirm' => true]] as $input) {
            $res = $controller->deleteAccount(7, $input);
            $this->assertSame('error', $res['status']);
            $this->assertSame(400, $res['http_code']);
        }
    }

    public function test_delete_account_with_confirm_deletes_the_row(): void
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects($this->once())->method('delete')->with(7);

        $res = $this->controller($users)->deleteAccount(7, ['confirm' => 'BORRAR']);
        $this->assertSame('success', $res['status']);
        $this->assertSame('{}', json_encode($res['data']));
    }

    /** @return array{0: string, 1: int} [status, http_code] devueltos por el middleware */
    private function runMiddleware(?User $user): array
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($user);
        $jwt = new JWTService();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt->generate(['user_id' => 7]);

        $middleware = new AuthenticationMiddleware(new NullLogger(), $jwt, $users, new EmailWhitelist());
        $res = $middleware->handle(['action' => 'me'], fn () => ['status' => 'success', 'http_code' => 200]);
        unset($_SERVER['HTTP_AUTHORIZATION']);
        return [$res['status'], $res['http_code']];
    }

    public function test_middleware_passes_active_user(): void
    {
        $this->assertSame(['success', 200], $this->runMiddleware($this->user(true)));
    }

    public function test_middleware_rejects_inactive_user_with_403(): void
    {
        $this->assertSame(['error', 403], $this->runMiddleware($this->user(false)));
    }

    public function test_middleware_rejects_deleted_user_with_401(): void
    {
        $this->assertSame(['error', 401], $this->runMiddleware(null));
    }
}
