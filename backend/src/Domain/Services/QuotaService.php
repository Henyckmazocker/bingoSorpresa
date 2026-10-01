<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Exceptions\QuotaExceededException;
use PDO;

/**
 * Cuota de almacenamiento por usuario (200 MB), sobre `users.storage_bytes` (= suma de
 * `uploads.bytes`). Las tres operaciones se llaman DENTRO de la transacción del que sube o borra:
 *   - `lockAndCheck()` bloquea la fila del usuario (FOR UPDATE) y comprueba que caben los bytes
 *     ANTES de escribir nada en disco. El bloqueo serializa dos subidas simultáneas del mismo
 *     usuario: la segunda espera y ve la cuota ya cobrada.
 *   - `charge()` suma, en la misma transacción que inserta el upload.
 *   - `release()` resta, en la misma transacción que borra los uploads huérfanos.
 */
class QuotaService
{
    public const QUOTA_BYTES = 200 * 1024 * 1024;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return int los bytes que el usuario tiene ocupados ahora
     * @throws QuotaExceededException si `$incoming` no cabe
     */
    public function lockAndCheck(int $userId, int $incoming): int
    {
        $this->assertInTransaction();
        $stmt = $this->db->prepare('SELECT storage_bytes FROM users WHERE id = :id' . $this->forUpdate());
        $stmt->execute(['id' => $userId]);
        $used = $stmt->fetchColumn();
        if ($used === false) {
            throw new \RuntimeException("User {$userId} not found");
        }
        $used = (int) $used;
        if ($used + $incoming > self::QUOTA_BYTES) {
            throw new QuotaExceededException(sprintf(
                'No queda espacio: llevas %s de %s. Borra fotos o bingos para subir más.',
                self::human($used),
                self::human(self::QUOTA_BYTES)
            ));
        }
        return $used;
    }

    public function charge(int $userId, int $bytes): void
    {
        $this->assertInTransaction();
        $stmt = $this->db->prepare('UPDATE users SET storage_bytes = storage_bytes + :b WHERE id = :id');
        $stmt->execute(['b' => $bytes, 'id' => $userId]);
    }

    /** Resta sin bajar de 0 (la columna es UNSIGNED: restar de más sería un error de MySQL). */
    public function release(int $userId, int $bytes): void
    {
        $this->assertInTransaction();
        $stmt = $this->db->prepare(
            'UPDATE users SET storage_bytes = CASE WHEN storage_bytes > :b1 THEN storage_bytes - :b2 ELSE 0 END'
            . ' WHERE id = :id'
        );
        $stmt->execute(['b1' => $bytes, 'b2' => $bytes, 'id' => $userId]);
    }

    public function usedBytes(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT storage_bytes FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** «12,3 MB». */
    public static function human(int $bytes): string
    {
        return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
    }

    private function assertInTransaction(): void
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('QuotaService must run inside the caller\'s transaction');
        }
    }

    /** SQLite (tests) no conoce FOR UPDATE; allí toda la transacción ya es exclusiva. */
    private function forUpdate(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }
}
