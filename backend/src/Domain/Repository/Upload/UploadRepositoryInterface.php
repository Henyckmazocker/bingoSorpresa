<?php

declare(strict_types=1);

namespace App\Domain\Repository\Upload;

/**
 * Repositorio de `uploads`. Los uploads son del USUARIO, no del bingo: se deduplican por
 * `(user_id, sha256)` y se reutilizan entre bingos.
 */
interface UploadRepositoryInterface
{
    public function findBySha(int $userId, string $sha256): ?array;

    public function insert(int $userId, string $sha256, int $width, int $height, int $bytes): int;

    /**
     * Borra los uploads del usuario que ya no referencia NINGÚN `bingo_items` (de ningún bingo) y
     * devuelve sus filas (`id`, `sha256`, `bytes`) para restar cuota y borrar los ficheros.
     * Llamar dentro de la transacción del borrado.
     *
     * @return array<int, array{id:int, sha256:string, bytes:int}>
     */
    public function deleteOrphans(int $userId): array;

    /**
     * De los `$ids` pedidos, los que siguen existiendo y son del usuario (para re-firmar los
     * snapshots de `bingo_prints`: un upload borrado se sirve sin URL).
     * @param int[] $ids
     * @return int[]
     */
    public function existingIds(int $userId, array $ids): array;
}
