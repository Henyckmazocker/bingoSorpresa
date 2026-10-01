<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Middleware\RateLimitMiddleware;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** RateLimitMiddleware sobre SQLite en memoria con un reloj de mentira (mismo esquema que init.sql). */
class RateLimitMiddlewareTest extends TestCase
{
    private PDO $db;
    private int $now = 1_800_000_030; // 30 s dentro de una ventana de 60 s
    private bool $purge = false;
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(
            'CREATE TABLE rate_limits (
                bucket VARCHAR(120) NOT NULL,
                window_start INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (bucket, window_start)
            )'
        );
        $_SERVER['REMOTE_ADDR'] = '172.18.0.5';
        unset($_SERVER['HTTP_X_REAL_IP']);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
    }

    private function middleware(array $config): RateLimitMiddleware
    {
        $m = new RateLimitMiddleware($this->db, new NullLogger(), fn (): int => $this->now, fn (): bool => $this->purge);
        $m->setConfig($config);
        return $m;
    }

    private function call(RateLimitMiddleware $m, array $request): array
    {
        return $m->handle($request + ['data' => []], fn (array $r) => ['status' => 'success', 'http_code' => 200]);
    }

    public function testLaUndecimaPeticionDeLoginEnUnMinutoDa429ConRetryAfter(): void
    {
        $_SERVER['HTTP_X_REAL_IP'] = '203.0.113.7';
        $m = $this->middleware(['limit' => 10, 'window' => 60, 'by' => 'ip']);
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(200, $this->call($m, ['action' => 'login'])['http_code'], "petición {$i}");
        }
        $r = $this->call($m, ['action' => 'login']);
        $this->assertSame(429, $r['http_code']);
        $this->assertSame(30, $r['data']['retryAfter']); // 30 s hasta el fin de la ventana

        $row = $this->db->query('SELECT * FROM rate_limits')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('login:203.0.113.7', $row['bucket']);
        $this->assertSame(1_800_000_000, (int) $row['window_start']);
        $this->assertSame(11, (int) $row['hits']);
    }

    public function testLaVentanaSiguienteEmpiezaDeCero(): void
    {
        $m = $this->middleware(['limit' => 2, 'window' => 60, 'by' => 'ip']);
        $this->call($m, ['action' => 'login']);
        $this->call($m, ['action' => 'login']);
        $this->assertSame(429, $this->call($m, ['action' => 'login'])['http_code']);
        $this->now += 30; // 1_800_000_060: ventana nueva
        $this->assertSame(200, $this->call($m, ['action' => 'login'])['http_code']);
    }

    public function testSinXRealIpUsaRemoteAddrYCadaIpTieneSuCubo(): void
    {
        $m = $this->middleware(['limit' => 1, 'window' => 60, 'by' => 'ip']);
        $this->assertSame(200, $this->call($m, ['action' => 'login'])['http_code']);
        $this->assertSame(429, $this->call($m, ['action' => 'login'])['http_code']);
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.1';
        $this->assertSame(200, $this->call($m, ['action' => 'login'])['http_code']);
        $_SERVER['HTTP_X_REAL_IP'] = 'no-es-una-ip';
        $this->assertSame(429, $this->call($m, ['action' => 'login'])['http_code']); // cae a REMOTE_ADDR

        $buckets = $this->db->query('SELECT bucket FROM rate_limits ORDER BY bucket')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['login:172.18.0.5', 'login:198.51.100.1'], $buckets);
    }

    public function testPorUsuarioYPorDispositivoSinGuardarElTokenEnClaro(): void
    {
        $m = $this->middleware(['limit' => 1, 'window' => 3600, 'by' => 'user']);
        $this->assertSame(200, $this->call($m, ['action' => 'upload_image', 'user_id' => 7])['http_code']);
        $this->assertSame(429, $this->call($m, ['action' => 'upload_image', 'user_id' => 7])['http_code']);
        $this->assertSame(200, $this->call($m, ['action' => 'upload_image', 'user_id' => 8])['http_code']);

        $token = str_repeat('ab', 32);
        $d = $this->middleware(['limit' => 40, 'window' => 60, 'by' => 'device']);
        $this->call($d, ['action' => 'pair_poll', 'data' => ['deviceToken' => $token]]);

        $buckets = $this->db->query('SELECT bucket FROM rate_limits ORDER BY bucket')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['pair_poll:' . hash('sha256', $token), 'upload_image:7', 'upload_image:8'], $buckets);
        $this->assertStringNotContainsString($token, implode(',', $buckets));
    }

    public function testRetryAfterHastaElFinDeUnaVentanaLarga(): void
    {
        $this->now = 1_800_000_000 + 100; // 1_800_000_000 es múltiplo de 600 → 500 s de ventana por delante
        $m = $this->middleware(['limit' => 1, 'window' => 600, 'by' => 'ip']);
        $this->call($m, ['action' => 'pair_create']);
        $r = $this->call($m, ['action' => 'pair_create']);
        $this->assertSame(429, $r['http_code']);
        $this->assertSame(500, $r['data']['retryAfter']);
    }

    public function testLosHitsSeSaturanSinDesbordar(): void
    {
        $this->db->exec("INSERT INTO rate_limits VALUES ('login:172.18.0.5', 1800000000, 65535)");
        $m = $this->middleware(['limit' => 10, 'window' => 60, 'by' => 'ip']);
        $this->assertSame(429, $this->call($m, ['action' => 'login'])['http_code']);
        $this->assertSame(65535, (int) $this->db->query('SELECT hits FROM rate_limits')->fetchColumn());
    }

    public function testLaLimpiezaBorraSoloLasVentanasDeHaceMasDeDosHoras(): void
    {
        $this->db->exec("INSERT INTO rate_limits VALUES ('login:vieja', " . ($this->now - 7201) . ', 3)');
        $this->db->exec("INSERT INTO rate_limits VALUES ('login:reciente', " . ($this->now - 7000) . ', 3)');
        $this->purge = true;
        $this->call($this->middleware(['limit' => 10, 'window' => 60, 'by' => 'ip']), ['action' => 'login']);

        $buckets = $this->db->query('SELECT bucket FROM rate_limits ORDER BY bucket')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['login:172.18.0.5', 'login:reciente'], $buckets);
    }

    public function testConfiguracionInvalidaFallaAlDeclarar(): void
    {
        $this->expectException(\LogicException::class);
        $this->middleware(['limit' => 10, 'window' => 60, 'by' => 'email']);
    }
}
