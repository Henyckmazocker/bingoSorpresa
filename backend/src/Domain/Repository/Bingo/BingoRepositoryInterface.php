<?php

declare(strict_types=1);

namespace App\Domain\Repository\Bingo;

/**
 * Repositorio de `bingos` y sus `bingo_items`. Devuelve filas (arrays) de la BD: la forma de la API
 * la pone `BingoPresenter`. Las búsquedas «Owned» filtran por dueño: un bingo ajeno no existe (404).
 */
interface BingoRepositoryInterface
{
    public function countByUser(int $userId): int;

    /**
     * Bingos del usuario, más recientes primero, con `music_count`, `image_count` y `prints_count`.
     * @return array<int, array<string, mixed>>
     */
    public function listByUser(int $userId): array;

    /** Fila de `bingos` si es del usuario; null si no existe o es de otro. */
    public function findOwned(int $bingoId, int $userId, bool $forUpdate = false): ?array;

    /**
     * Fila de `bingos` con ese `share_token`, si el compartido está activo y su dueño también
     * (`users.is_active = 1`); null si no.
     */
    public function findShared(string $shareToken): ?array;

    public function create(int $userId, string $title): int;

    /** @param array<string, scalar|null> $columns columnas de `bingos` ya validadas */
    public function update(int $bingoId, array $columns): void;

    public function delete(int $bingoId): void;

    /** Copia la fila (sin `share_token`) y sus items, que apuntan a los MISMOS uploads. */
    public function duplicate(int $bingoId, string $newTitle): int;

    public function setShareToken(int $bingoId, ?string $token): void;

    public function printsCount(int $bingoId): int;

    /**
     * Items del bingo en orden de inserción, con `width`/`height`/`sha256` del upload si es imagen.
     * @return array<int, array<string, mixed>>
     */
    public function items(int $bingoId): array;

    public function countItems(int $bingoId, string $kind): int;

    /** Item con su `user_id` (el del bingo) y datos del upload, si el bingo es del usuario. */
    public function findItemOwned(int $itemId, int $userId): ?array;

    public function findItem(int $itemId): ?array;

    public function insertItem(int $bingoId, string $kind, string $label, ?string $sublabel, ?int $uploadId): int;

    /** Canción de YouTube (kind music): sin upload, con su `youtube_video_id` y su inicio. */
    public function insertMusicItem(int $bingoId, string $label, ?string $sublabel, string $videoId, ?int $startSeconds): int;

    /** `youtube_video_id` de las canciones del bingo (para el `duplicate` de add_music_items). @return string[] */
    public function musicVideoIds(int $bingoId): array;

    public function updateItem(int $itemId, string $label, ?string $sublabel): void;

    public function setItemStart(int $itemId, ?int $startSeconds): void;

    public function deleteItem(int $itemId): void;

    /** Marca el bingo como modificado (cambian sus items). */
    public function touch(int $bingoId): void;
}
