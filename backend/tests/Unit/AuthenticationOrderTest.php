<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Model\User;
use App\Domain\Model\ValueObjects\Email;
use App\Domain\Model\ValueObjects\GoogleId;
use App\Domain\Repository\User\UserRepositoryInterface;
use App\Domain\Services\EmailWhitelist;
use App\Infrastructure\Auth\JWTService;
use App\Infrastructure\Middleware\AuthenticationMiddleware;
use App\Infrastructure\Middleware\CSRFMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Deuda de la Apertura Pública, M1: si viene Bearer, decide él (JWT antes que sesión; un Bearer
 * inválido es 401 sin recurrir a la cookie). Solo con cookie sigue entrando como 'session' y un
 * CSRF erróneo da 403 con error_code CSRF_INVALID.
 */
class AuthenticationOrderTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['ALLOWED_EMAILS'] = '';
        $_ENV['JWT_SECRET'] ??= 'test-secret-de-al-menos-32-caracteres!!';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_data'], $_SESSION['csrf_token'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    private function user(): User
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
            true
        );
    }

    /**
     * Pasa el middleware y devuelve [respuesta, $request recibido por $next o null si no se llamó].
     * @return array{0: array, 1: ?array}
     */
    private function runMiddleware(JWTService $jwt): array
    {
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->method('findById')->willReturn($this->user());

        $middleware = new AuthenticationMiddleware(new NullLogger(), $jwt, $users, new EmailWhitelist());
        $seen = null;
        $res = $middleware->handle(['action' => 'me'], function (array $request) use (&$seen) {
            $seen = $request;
            return ['status' => 'success', 'http_code' => 200];
        });
        return [$res, $seen];
    }

    public function test_session_plus_valid_bearer_authenticates_as_jwt(): void
    {
        $jwt = new JWTService();
        $_SESSION['user_data'] = ['id' => 7, 'email' => 'test@example.com'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $jwt->generate(['user_id' => 7]);

        [$res, $seen] = $this->runMiddleware($jwt);
        $this->assertSame('success', $res['status']);
        $this->assertNotNull($seen);
        $this->assertSame('jwt', $seen['auth_method']);
        $this->assertSame(7, $seen['user_id']);
    }

    public function test_session_plus_invalid_bearer_is_401_without_falling_back(): void
    {
        $_SESSION['user_data'] = ['id' => 7, 'email' => 'test@example.com'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer no-es-un-jwt';

        [$res, $seen] = $this->runMiddleware(new JWTService());
        $this->assertSame('error', $res['status']);
        $this->assertSame(401, $res['http_code']);
        $this->assertNull($seen, '$next no debe llamarse');
    }

    public function test_session_only_authenticates_as_session(): void
    {
        $_SESSION['user_data'] = ['id' => 7, 'email' => 'test@example.com'];

        [$res, $seen] = $this->runMiddleware(new JWTService());
        $this->assertSame('success', $res['status']);
        $this->assertNotNull($seen);
        $this->assertSame('session', $seen['auth_method']);
    }

    public function test_csrf_with_session_and_wrong_token_is_403_csrf_invalid(): void
    {
        $_SESSION['csrf_token'] = 'el-bueno';
        $called = false;

        $res = (new CSRFMiddleware(new NullLogger()))->handle(
            ['action' => 'update_bingo', 'auth_method' => 'session', 'csrf_token' => 'otro'],
            function () use (&$called) {
                $called = true;
                return ['status' => 'success'];
            }
        );
        $this->assertFalse($called);
        $this->assertSame('error', $res['status']);
        $this->assertSame(403, $res['http_code']);
        $this->assertSame('CSRF_INVALID', $res['error_code']);
    }
}
