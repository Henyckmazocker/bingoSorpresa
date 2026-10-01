<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Services\PairingService;
use PDO;
use PHPUnit\Framework\TestCase;

/** PairingService sobre SQLite en memoria con un reloj de mentira (misma SQL que en MySQL). */
class PairingServiceTest extends TestCase
{
    private PDO $db;
    private int $now = 1_800_000_000;
    private PairingService $service;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec(
            'CREATE TABLE tv_pairings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code CHAR(6) NOT NULL UNIQUE,
                device_token_hash CHAR(64) NOT NULL,
                bingo_id INTEGER NULL,
                claimed_by INTEGER NULL,
                expires_at DATETIME NOT NULL
            )'
        );
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL DEFAULT 1)');
        $this->service = new PairingService($this->db, fn (): int => $this->now);
    }

    public function testCreaCodigoDeSeisCifrasYSoloGuardaElHashDelToken(): void
    {
        $p = $this->service->create();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $p['code']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $p['deviceToken']);
        $this->assertSame(600, $p['expiresIn']);

        $row = $this->db->query('SELECT * FROM tv_pairings')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(hash('sha256', $p['deviceToken']), $row['device_token_hash']);
        $this->assertStringNotContainsString($p['deviceToken'], json_encode($row));
        $this->assertSame(gmdate('Y-m-d H:i:s', $this->now + 600), $row['expires_at']);
    }

    private function addUser(int $id, int $isActive = 1): void
    {
        $this->db->prepare('INSERT INTO users (id, is_active) VALUES (:id, :a)')
            ->execute(['id' => $id, 'a' => $isActive]);
    }

    public function testEsperaHastaQueSeReclamaYLuegoEstaListo(): void
    {
        $this->addUser(7);
        $p = $this->service->create();
        $this->assertSame(['bingoId' => null, 'ownerId' => null], $this->service->poll($p['deviceToken']));

        $this->assertSame(PairingService::CLAIM_OK, $this->service->claim($p['code'], 42, 7));
        $this->assertSame(['bingoId' => 42, 'ownerId' => 7], $this->service->poll($p['deviceToken']));
        // Entregar el payload no borra el pairing: un sondeo perdido se repite y vuelve a estar listo.
        $this->assertSame(['bingoId' => 42, 'ownerId' => 7], $this->service->poll($p['deviceToken']));
    }

    public function testNoSePuedeReclamarDosVeces(): void
    {
        $this->addUser(7);
        $p = $this->service->create();
        $this->assertSame(PairingService::CLAIM_OK, $this->service->claim($p['code'], 42, 7));
        $this->assertSame(PairingService::CLAIM_TAKEN, $this->service->claim($p['code'], 43, 8));
        // El segundo no pisa al primero.
        $this->assertSame(['bingoId' => 42, 'ownerId' => 7], $this->service->poll($p['deviceToken']));
    }

    public function testCaducaALosDiezMinutos(): void
    {
        $p = $this->service->create();

        $this->now += 599;
        $this->assertNotNull($this->service->poll($p['deviceToken']));

        $this->now += 1;
        $this->assertNull($this->service->poll($p['deviceToken']));
        $this->assertSame(PairingService::CLAIM_NOT_FOUND, $this->service->claim($p['code'], 42, 7));
    }

    public function testReclamarAlFinalDaMargenParaElSondeo(): void
    {
        $this->addUser(7);
        $p = $this->service->create();
        $this->now += 599;
        $this->assertSame(PairingService::CLAIM_OK, $this->service->claim($p['code'], 42, 7));

        $this->now += PairingService::CLAIM_GRACE - 1;
        $this->assertSame(['bingoId' => 42, 'ownerId' => 7], $this->service->poll($p['deviceToken']));
        $this->now += 1;
        $this->assertNull($this->service->poll($p['deviceToken']));
    }

    public function testDuenoActivoRecibeElBingo(): void
    {
        $this->addUser(7, 1);
        $p = $this->service->create();
        $this->service->claim($p['code'], 42, 7);

        $this->assertSame(['bingoId' => 42, 'ownerId' => 7], $this->service->poll($p['deviceToken']));
    }

    public function testDuenoDeBajaDaComoCaducado(): void
    {
        $this->addUser(7, 0);
        $p = $this->service->create();
        $this->assertSame(PairingService::CLAIM_OK, $this->service->claim($p['code'], 42, 7));

        // Sin pista de la baja: la tele lo ve como un código caducado (410) y pide otro.
        $this->assertNull($this->service->poll($p['deviceToken']));
    }

    public function testSinReclamarEsperaAunqueNoHayaUsuarios(): void
    {
        $this->assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $p = $this->service->create();

        $this->assertSame(['bingoId' => null, 'ownerId' => null], $this->service->poll($p['deviceToken']));
    }

    public function testCodigoOTokenDesconocidos(): void
    {
        $this->assertNull($this->service->poll(str_repeat('a', 64)));
        $this->assertSame(PairingService::CLAIM_NOT_FOUND, $this->service->claim('123456', 42, 7));
    }

    public function testCreateBorraLosCaducadosYNoLosVivos(): void
    {
        $old = $this->service->create();
        $this->now += 300;
        $alive = $this->service->create();
        $this->now += 300; // el primero llega justo a su caducidad

        $this->service->create();

        $codes = $this->db->query('SELECT code FROM tv_pairings')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains($old['code'], $codes);
        $this->assertContains($alive['code'], $codes);
        $this->assertCount(2, $codes);
    }

    public function testFormas(): void
    {
        $this->assertTrue(PairingService::isCode('012345'));
        $this->assertFalse(PairingService::isCode('12345'));
        $this->assertFalse(PairingService::isCode(123456));
        $this->assertFalse(PairingService::isCode("123456\n"));
        $this->assertTrue(PairingService::isDeviceToken(str_repeat('0f', 32)));
        $this->assertFalse(PairingService::isDeviceToken(str_repeat('G', 64)));
    }
}
