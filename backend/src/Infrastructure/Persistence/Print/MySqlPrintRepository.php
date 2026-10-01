<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Print;

use App\Domain\Repository\Print\PrintRepositoryInterface;
use PDO;

/** MySQL de `bingo_prints` (caen por CASCADE al borrar el bingo). */
class MySqlPrintRepository implements PrintRepositoryInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $bingoId, string $seed, int $players, array $snapshot): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bingo_prints (bingo_id, seed, players, snapshot) VALUES (:b, :s, :p, :snap)'
        );
        $stmt->execute([
            'b' => $bingoId,
            's' => $seed,
            'p' => $players,
            'snap' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function listByBingo(int $bingoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, seed, players, created_at FROM bingo_prints WHERE bingo_id = :b ORDER BY id'
        );
        $stmt->execute(['b' => $bingoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOwned(int $printId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, b.user_id
               FROM bingo_prints p
               JOIN bingos b ON b.id = p.bingo_id
              WHERE p.id = :id AND b.user_id = :u'
        );
        $stmt->execute(['id' => $printId, 'u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['snapshot'] = json_decode((string) $row['snapshot'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }
}
