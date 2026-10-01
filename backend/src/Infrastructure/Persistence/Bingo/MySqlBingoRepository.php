<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Bingo;

use App\Domain\Repository\Bingo\BingoRepositoryInterface;
use PDO;

/**
 * MySQL de `bingos` + `bingo_items`. Sin transacciones propias: las abre el controlador cuando una
 * operación toca varias tablas (borrados con limpieza de uploads, subidas con cuota).
 */
class MySqlBingoRepository implements BingoRepositoryInterface
{
    /** Columnas de `bingos` que se pueden escribir con update(). */
    private const WRITABLE = [
        'title', 'numeric_enabled',
        'music_enabled', 'music_label', 'music_rows', 'music_cols',
        'image_enabled', 'image_label', 'image_rows', 'image_cols',
    ];

    /** Lo que se copia al duplicar (todo menos id, user_id, title, share_token y fechas). */
    private const COPIED = [
        'numeric_enabled', 'numeric_variant',
        'music_enabled', 'music_label', 'music_rows', 'music_cols',
        'image_enabled', 'image_label', 'image_rows', 'image_cols',
        'lead_in', 'min_gap', 'spread_over',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public function countByUser(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM bingos WHERE user_id = :u');
        $stmt->execute(['u' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function listByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT b.*,
                    (SELECT COUNT(*) FROM bingo_items i WHERE i.bingo_id = b.id AND i.kind = 'music') AS music_count,
                    (SELECT COUNT(*) FROM bingo_items i WHERE i.bingo_id = b.id AND i.kind = 'image') AS image_count,
                    (SELECT COUNT(*) FROM bingo_prints p WHERE p.bingo_id = b.id) AS prints_count
               FROM bingos b
              WHERE b.user_id = :u
              ORDER BY b.updated_at DESC, b.id DESC"
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOwned(int $bingoId, int $userId, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM bingos WHERE id = :id AND user_id = :u' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $bingoId, 'u' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findShared(string $shareToken): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT b.*
               FROM bingos b
               JOIN users u ON u.id = b.user_id AND u.is_active = 1
              WHERE b.share_token = :t'
        );
        $stmt->execute(['t' => $shareToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(int $userId, string $title): int
    {
        $stmt = $this->db->prepare('INSERT INTO bingos (user_id, title) VALUES (:u, :t)');
        $stmt->execute(['u' => $userId, 't' => $title]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $bingoId, array $columns): void
    {
        $columns = array_intersect_key($columns, array_flip(self::WRITABLE));
        if (!$columns) {
            return;
        }
        $set = implode(', ', array_map(fn ($c) => "{$c} = :{$c}", array_keys($columns)));
        $stmt = $this->db->prepare("UPDATE bingos SET {$set} WHERE id = :id");
        $params = [];
        foreach ($columns as $c => $v) {
            $params[$c] = is_bool($v) ? (int) $v : $v;
        }
        $params['id'] = $bingoId;
        $stmt->execute($params);
    }

    public function delete(int $bingoId): void
    {
        // bingo_items, bingo_prints y tv_pairings caen por CASCADE. Los uploads NO (RESTRICT): los
        // limpia el llamador con UploadRepository::deleteOrphans en la misma transacción.
        $stmt = $this->db->prepare('DELETE FROM bingos WHERE id = :id');
        $stmt->execute(['id' => $bingoId]);
    }

    public function duplicate(int $bingoId, string $newTitle): int
    {
        $cols = implode(', ', self::COPIED);
        $stmt = $this->db->prepare(
            "INSERT INTO bingos (user_id, title, {$cols})
             SELECT user_id, :t, {$cols} FROM bingos WHERE id = :id"
        );
        $stmt->execute(['t' => $newTitle, 'id' => $bingoId]);
        $newId = (int) $this->db->lastInsertId();

        // Mismo orden (id ascendente) → mismo orden de inserción en la copia.
        $stmt = $this->db->prepare(
            'INSERT INTO bingo_items (bingo_id, kind, label, sublabel, upload_id, youtube_video_id, start_seconds, end_seconds)
             SELECT :new, kind, label, sublabel, upload_id, youtube_video_id, start_seconds, end_seconds
               FROM bingo_items WHERE bingo_id = :old ORDER BY id'
        );
        $stmt->execute(['new' => $newId, 'old' => $bingoId]);
        return $newId;
    }

    public function setShareToken(int $bingoId, ?string $token): void
    {
        $stmt = $this->db->prepare('UPDATE bingos SET share_token = :t WHERE id = :id');
        $stmt->execute(['t' => $token, 'id' => $bingoId]);
    }

    public function printsCount(int $bingoId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM bingo_prints WHERE bingo_id = :id');
        $stmt->execute(['id' => $bingoId]);
        return (int) $stmt->fetchColumn();
    }

    public function items(int $bingoId): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, b.user_id, u.width, u.height, u.sha256
               FROM bingo_items i
               JOIN bingos b ON b.id = i.bingo_id
               LEFT JOIN uploads u ON u.id = i.upload_id
              WHERE i.bingo_id = :id
              ORDER BY i.id'
        );
        $stmt->execute(['id' => $bingoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countItems(int $bingoId, string $kind): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM bingo_items WHERE bingo_id = :id AND kind = :k');
        $stmt->execute(['id' => $bingoId, 'k' => $kind]);
        return (int) $stmt->fetchColumn();
    }

    public function findItemOwned(int $itemId, int $userId): ?array
    {
        $row = $this->findItem($itemId);
        return ($row && (int) $row['user_id'] === $userId) ? $row : null;
    }

    public function findItem(int $itemId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT i.*, b.user_id, u.width, u.height, u.sha256
               FROM bingo_items i
               JOIN bingos b ON b.id = i.bingo_id
               LEFT JOIN uploads u ON u.id = i.upload_id
              WHERE i.id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertItem(int $bingoId, string $kind, string $label, ?string $sublabel, ?int $uploadId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO bingo_items (bingo_id, kind, label, sublabel, upload_id) VALUES (:b, :k, :l, :s, :up)'
        );
        $stmt->execute(['b' => $bingoId, 'k' => $kind, 'l' => $label, 's' => $sublabel, 'up' => $uploadId]);
        return (int) $this->db->lastInsertId();
    }

    public function insertMusicItem(int $bingoId, string $label, ?string $sublabel, string $videoId, ?int $startSeconds): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO bingo_items (bingo_id, kind, label, sublabel, youtube_video_id, start_seconds)
             VALUES (:b, 'music', :l, :s, :v, :st)"
        );
        $stmt->execute(['b' => $bingoId, 'l' => $label, 's' => $sublabel, 'v' => $videoId, 'st' => $startSeconds]);
        return (int) $this->db->lastInsertId();
    }

    public function musicVideoIds(int $bingoId): array
    {
        $stmt = $this->db->prepare(
            "SELECT youtube_video_id FROM bingo_items
              WHERE bingo_id = :id AND kind = 'music' AND youtube_video_id IS NOT NULL"
        );
        $stmt->execute(['id' => $bingoId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function updateItem(int $itemId, string $label, ?string $sublabel): void
    {
        $stmt = $this->db->prepare('UPDATE bingo_items SET label = :l, sublabel = :s WHERE id = :id');
        $stmt->execute(['l' => $label, 's' => $sublabel, 'id' => $itemId]);
    }

    public function setItemStart(int $itemId, ?int $startSeconds): void
    {
        $stmt = $this->db->prepare('UPDATE bingo_items SET start_seconds = :st WHERE id = :id');
        $stmt->execute(['st' => $startSeconds, 'id' => $itemId]);
    }

    public function deleteItem(int $itemId): void
    {
        $stmt = $this->db->prepare('DELETE FROM bingo_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);
    }

    public function touch(int $bingoId): void
    {
        $stmt = $this->db->prepare('UPDATE bingos SET updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute(['id' => $bingoId]);
    }
}
