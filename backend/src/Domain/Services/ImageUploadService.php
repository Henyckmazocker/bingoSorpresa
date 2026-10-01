<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Exceptions\ImageRejectedException;
use App\Domain\Exceptions\QuotaExceededException;
use App\Domain\Repository\Bingo\BingoRepositoryInterface;
use App\Domain\Repository\Upload\UploadRepositoryInterface;
use PDO;

/**
 * Añade una foto a un bingo: el camino ÚNICO de una imagen hasta `uploads` + `bingo_items`. Lo usan
 * `upload_image` (UploadController, fichero de `$_FILES`) y el import de la fiesta de casa
 * (`scripts/import_local_party.php`, fichero local), así que los dos pasan por las mismas guardas,
 * la misma deduplicación y la misma cuota.
 *
 * 1. Límite de fotos del bingo (300) → ImageRejectedException 400.
 * 2. Guardas de ImageProcessor (sin decodificar) → 400/413/415/422.
 * 3. sha256 del ORIGINAL. Si el usuario ya lo subió (a cualquier bingo) → item nuevo apuntando al
 *    upload existente, sin reencodar ni cobrar cuota (`deduplicated: true`).
 * 4. Si no, reencoda FUERA de la transacción (no se bloquea a nadie mientras GD trabaja).
 * 5. Transacción: bloquea la fila del usuario, comprueba la cuota (QuotaExceededException) ANTES de
 *    escribir, escribe los ficheros, inserta upload + item y suma `storage_bytes`. Si algo falla,
 *    rollback y fuera los ficheros recién escritos.
 *
 * El llamador comprueba antes que el bingo es del usuario.
 */
class ImageUploadService
{
    public function __construct(
        private readonly PDO $db,
        private readonly BingoRepositoryInterface $bingos,
        private readonly UploadRepositoryInterface $uploads,
        private readonly ImageProcessor $processor,
        private readonly QuotaService $quota,
        private readonly BingoValidator $validator,
        private readonly UploadStorage $storage
    ) {
    }

    /**
     * @return array{itemId:int, uploadId:int, deduplicated:bool}
     * @throws ImageRejectedException|QuotaExceededException
     */
    public function store(int $userId, int $bingoId, string $path, int $uploadError, string $label): array
    {
        $this->checkItemLimit($bingoId);

        $info = $this->processor->inspect($path, $uploadError);
        $sha = $this->processor->sha256($path);
        $processed = $this->uploads->findBySha($userId, $sha) ? null : $this->processor->process($path, $info);

        $written = false;
        $this->db->beginTransaction();
        try {
            $incoming = $processed ? strlen($processed['main']) + strlen($processed['thumb']) : 0;
            $this->quota->lockAndCheck($userId, $incoming);

            // Con la fila del usuario bloqueada: nadie más está subiendo ni borrando por él.
            $this->checkItemLimit($bingoId);

            $existing = $this->uploads->findBySha($userId, $sha);
            if ($existing) {
                $uploadId = (int) $existing['id'];
                $deduplicated = true;
            } else {
                // Carrera rara: el upload existía al mirar y lo borraron antes del bloqueo.
                if ($processed === null) {
                    $processed = $this->processor->process($path, $info);
                    $this->quota->lockAndCheck($userId, strlen($processed['main']) + strlen($processed['thumb']));
                }
                $bytes = $this->storage->write($userId, $sha, $processed['main'], $processed['thumb']);
                $written = true;
                $uploadId = $this->uploads->insert($userId, $sha, $processed['width'], $processed['height'], $bytes);
                $this->quota->charge($userId, $bytes);
                $deduplicated = false;
            }

            $itemId = $this->bingos->insertItem($bingoId, 'image', $label, null, $uploadId);
            $this->bingos->touch($bingoId);
            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($written) {
                $this->storage->remove($userId, $sha);
            }
            throw $e;
        }

        return ['itemId' => $itemId, 'uploadId' => $uploadId, 'deduplicated' => $deduplicated];
    }

    private function checkItemLimit(int $bingoId): void
    {
        $limit = $this->validator->checkItemLimit('image', $this->bingos->countItems($bingoId, 'image'));
        if ($limit !== null) {
            throw new ImageRejectedException($limit, 400);
        }
    }
}
