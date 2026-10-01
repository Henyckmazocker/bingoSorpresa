<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exceptions\QuotaExceededException;
use App\Domain\Services\QuotaService;
use PDO;
use PHPUnit\Framework\TestCase;

/** QuotaService sobre SQLite en memoria (misma SQL salvo el FOR UPDATE, que SQLite no tiene). */
class QuotaServiceTest extends TestCase
{
    private PDO $db;
    private QuotaService $quota;

    protected function setUp(): void
    {
        $this->db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, storage_bytes INTEGER NOT NULL DEFAULT 0)');
        $this->db->exec('INSERT INTO users (id, storage_bytes) VALUES (1, 0), (2, 0)');
        $this->quota = new QuotaService($this->db);
    }

    private function inTx(callable $fn): void
    {
        $this->db->beginTransaction();
        $fn();
        $this->db->commit();
    }

    public function testCabeHastaElLimiteExacto(): void
    {
        $this->inTx(function () {
            $this->assertSame(0, $this->quota->lockAndCheck(1, QuotaService::QUOTA_BYTES));
        });
    }

    public function testRechazaLoQueNoCabeAntesDeEscribir(): void
    {
        $this->db->exec('UPDATE users SET storage_bytes = ' . (QuotaService::QUOTA_BYTES - 100) . ' WHERE id = 1');
        $this->db->beginTransaction();
        $this->assertSame(QuotaService::QUOTA_BYTES - 100, $this->quota->lockAndCheck(1, 100));
        $this->expectException(QuotaExceededException::class);
        try {
            $this->quota->lockAndCheck(1, 101);
        } finally {
            $this->db->rollBack();
        }
    }

    public function testCobraYDevuelveSoloAlUsuarioIndicado(): void
    {
        $this->inTx(fn () => $this->quota->charge(1, 5000));
        $this->assertSame(5000, $this->quota->usedBytes(1));
        $this->assertSame(0, $this->quota->usedBytes(2));

        $this->inTx(fn () => $this->quota->release(1, 2000));
        $this->assertSame(3000, $this->quota->usedBytes(1));
    }

    public function testReleaseNoBajaDeCero(): void
    {
        $this->inTx(function () {
            $this->quota->charge(1, 10);
            $this->quota->release(1, 50);
        });
        $this->assertSame(0, $this->quota->usedBytes(1));
    }

    public function testElCobroVaEnLaTransaccionDelLlamador(): void
    {
        $this->db->beginTransaction();
        $this->quota->charge(1, 777);
        $this->db->rollBack();
        $this->assertSame(0, $this->quota->usedBytes(1));
    }

    public function testExigeTransaccion(): void
    {
        $this->expectException(\LogicException::class);
        $this->quota->charge(1, 1);
    }
}
