<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Upload;

use App\Domain\Repository\Upload\UploadRepositoryInterface;
use PDO;

class MySqlUploadRepository implements UploadRepositoryInterface
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function findBySha(int $userId, string $sha256): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM uploads WHERE user_id = :u AND sha256 = :s');
        $stmt->execute(['u' => $userId, 's' => $sha256]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insert(int $userId, string $sha256, int $width, int $height, int $bytes): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO uploads (user_id, sha256, width, height, bytes) VALUES (:u, :s, :w, :h, :b)'
        );
        $stmt->execute(['u' => $userId, 's' => $sha256, 'w' => $width, 'h' => $height, 'b' => $bytes]);
        return (int) $this->db->lastInsertId();
    }

    public function deleteOrphans(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.sha256, u.bytes
               FROM uploads u
              WHERE u.user_id = :u
                AND NOT EXISTS (SELECT 1 FROM bingo_items i WHERE i.upload_id = u.id)
              FOR UPDATE'
        );
        $stmt->execute(['u' => $userId]);
        $orphans = array_map(
            fn ($r) => ['id' => (int) $r['id'], 'sha256' => (string) $r['sha256'], 'bytes' => (int) $r['bytes']],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
        if ($orphans) {
            $ids = array_column($orphans, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $del = $this->db->prepare("DELETE FROM uploads WHERE id IN ({$in})");
            $del->execute($ids);
        }
        return $orphans;
    }

    public function existingIds(int $userId, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id FROM uploads WHERE user_id = ? AND id IN ({$in})");
        $stmt->execute([$userId, ...$ids]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
