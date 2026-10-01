<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Exceptions\ImageRejectedException;
use App\Domain\Exceptions\QuotaExceededException;
use App\Domain\Repository\Bingo\BingoRepositoryInterface;
use App\Domain\Services\BingoPresenter;
use App\Domain\Services\ImageUploadService;
use App\Domain\Services\QuotaService;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * `upload_image` (multipart: action, bingo_id, file). Una foto por petición: el frontend sube en
 * serie.
 *
 * Aquí solo lo propio de HTTP (bingo del usuario, que haya llegado un fichero subido de verdad, el
 * texto a partir del nombre y los códigos de respuesta). Guardas, deduplicación, reencodado y cuota
 * están en `ImageUploadService`, que comparte con el import de la fiesta de casa.
 */
class UploadController extends BaseController
{
    private const ITEM_LABEL_MAX = 120;

    public function __construct(
        private readonly BingoRepositoryInterface $bingos,
        private readonly ImageUploadService $uploader,
        private readonly QuotaService $quota,
        private readonly BingoPresenter $presenter,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /** @param array|null $file la entrada de `$_FILES['file']` */
    public function uploadImage(int $userId, array $data, ?array $file): array
    {
        $bingoId = filter_var($data['bingo_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($bingoId === false) {
            throw new InvalidArgumentException("Field 'bingo_id' is required.");
        }
        if (!$this->bingos->findOwned($bingoId, $userId)) {
            return $this->errorResponse('Este bingo no existe.', 404);
        }
        if ($file === null || !isset($file['error']) || is_array($file['error'])) {
            return $this->errorResponse('No ha llegado ninguna foto.', 400);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        $error = (int) $file['error'];
        if ($error === UPLOAD_ERR_OK && !is_uploaded_file($tmp)) {
            return $this->errorResponse('No ha llegado ninguna foto.', 400);
        }

        try {
            $stored = $this->uploader->store($userId, $bingoId, $tmp, $error, $this->labelFromName((string) ($file['name'] ?? '')));
        } catch (ImageRejectedException $e) {
            $this->logger?->info('Upload rejected', ['user_id' => $userId, 'reason' => $e->getMessage(), 'code' => $e->getHttpCode()]);
            return $this->errorResponse($e->getMessage(), $e->getHttpCode());
        } catch (QuotaExceededException $e) {
            return $this->errorResponse($e->getMessage(), 507);
        }

        return $this->successResponse($stored['deduplicated'] ? 'Foto ya subida: reutilizada.' : 'Foto subida.', [
            'item' => $this->presenter->item($this->bingos->findItem($stored['itemId'])),
            'storageBytes' => $this->quota->usedBytes($userId),
            'deduplicated' => $stored['deduplicated'],
        ], 201);
    }

    /** «IMG_2041.jpg» → «IMG_2041». El texto de la foto se edita luego en el editor. */
    private function labelFromName(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $base) ?? '');
        if ($base === '' || !mb_check_encoding($base, 'UTF-8')) {
            return 'Foto';
        }
        return mb_substr($base, 0, self::ITEM_LABEL_MAX);
    }
}
