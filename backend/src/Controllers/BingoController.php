<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Bingo\BingoRepositoryInterface;
use App\Domain\Repository\Upload\UploadRepositoryInterface;
use App\Domain\Services\BingoPresenter;
use App\Domain\Services\BingoValidator;
use App\Domain\Services\QuotaService;
use App\Domain\Services\UploadStorage;
use App\Domain\Services\YoutubeUrlParser;
use App\Infrastructure\Youtube\OEmbedClient;
use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * CRUD de bingos y de sus items (el backoffice, «Construir»). Todo con sesión y solo sobre bingos
 * del usuario: uno ajeno responde 404, igual que uno que no existe.
 *
 * Borrados (`delete_bingo`, `delete_item`): en UNA transacción se borra lo pedido, después los
 * uploads del usuario que ya no referencia ningún item (de ningún bingo; el RESTRICT de
 * `bingo_items.upload_id` impide hacerlo antes) y se resta su cuota. Los ficheros se borran
 * DESPUÉS del commit: si el commit fallase, no habría filas apuntando a ficheros borrados.
 *
 * Canciones (`add_music_items`): enlaces de YouTube en lote; título y canal salen de oEmbed.
 */
class BingoController extends BaseController
{
    private const TITLE_MAX = 120;
    private const MODE_LABEL_MAX = 40;
    private const ITEM_LABEL_MAX = 120;
    /** Enlaces por petición de `add_music_items` (cada uno es una llamada a oEmbed de hasta 5 s). */
    private const MUSIC_URLS_MAX = 50;

    public function __construct(
        private readonly PDO $db,
        private readonly BingoRepositoryInterface $bingos,
        private readonly UploadRepositoryInterface $uploads,
        private readonly BingoValidator $validator,
        private readonly QuotaService $quota,
        private readonly BingoPresenter $presenter,
        private readonly UploadStorage $storage,
        private readonly YoutubeUrlParser $youtube,
        private readonly OEmbedClient $oembed,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function listBingos(int $userId): array
    {
        $rows = $this->bingos->listByUser($userId);
        return $this->successResponse('OK', array_map(fn ($r) => $this->presenter->summary($r), $rows));
    }

    public function createBingo(int $userId, array $data): array
    {
        $title = $this->title($data['title'] ?? null);
        $limit = $this->validator->checkBingoLimit($this->bingos->countByUser($userId));
        if ($limit !== null) {
            return $this->errorResponse($limit, 409);
        }
        $id = $this->bingos->create($userId, $title);
        return $this->successResponse('Bingo creado.', $this->dto($id, $userId), 201);
    }

    public function getBingo(int $userId, array $data): array
    {
        $id = $this->intParam($data, 'bingo_id');
        if (!$this->bingos->findOwned($id, $userId)) {
            return $this->notFound();
        }
        return $this->successResponse('OK', $this->dto($id, $userId));
    }

    /**
     * Patch de título y modos. Se valida el estado RESULTANTE (fila + patch + items actuales) con
     * E1–E4; con errores, 400 y `data.errors` y no se guarda nada.
     */
    public function updateBingo(int $userId, array $data): array
    {
        $id = $this->intParam($data, 'bingo_id');
        $columns = [];
        if (array_key_exists('title', $data)) {
            $columns['title'] = $this->title($data['title']);
        }
        $modes = $data['modes'] ?? [];
        if (!is_array($modes)) {
            throw new InvalidArgumentException('modes debe ser un objeto.');
        }
        if (isset($modes['numeric'])) {
            $columns += $this->numericPatch($modes['numeric']);
        }
        foreach (['music', 'image'] as $kind) {
            if (isset($modes[$kind])) {
                $columns += $this->surprisePatch($kind, $modes[$kind]);
            }
        }

        $this->db->beginTransaction();
        try {
            $row = $this->bingos->findOwned($id, $userId, true);
            if (!$row) {
                $this->db->rollBack();
                return $this->notFound();
            }
            $result = array_merge($row, $columns);
            $errors = $this->validator->validate($this->validationState($id, $result));
            if ($errors) {
                $this->db->rollBack();
                return [
                    'status' => 'error',
                    'message' => implode(' ', $errors),
                    'data' => ['errors' => $errors],
                    'http_code' => 400,
                ];
            }
            $this->bingos->update($id, $columns);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
        return $this->successResponse('Bingo guardado.', $this->dto($id, $userId));
    }

    public function deleteBingo(int $userId, array $data): array
    {
        $id = $this->intParam($data, 'bingo_id');
        $this->db->beginTransaction();
        try {
            // Primero la fila del usuario (mismo orden de bloqueos que upload_image: sin deadlocks).
            $this->quota->lockAndCheck($userId, 0);
            if (!$this->bingos->findOwned($id, $userId, true)) {
                $this->db->rollBack();
                return $this->notFound();
            }
            $this->bingos->delete($id);
            $orphans = $this->releaseOrphans($userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
        $this->removeFiles($userId, $orphans);
        $this->logger?->info('Bingo deleted', ['user_id' => $userId, 'bingo_id' => $id, 'uploads_freed' => count($orphans)]);
        // {} y no []: contrato de la API (como delete_account).
        return ['status' => 'success', 'message' => 'Bingo borrado.', 'data' => new \stdClass(), 'http_code' => 200];
    }

    public function duplicateBingo(int $userId, array $data): array
    {
        $id = $this->intParam($data, 'bingo_id');
        $row = $this->bingos->findOwned($id, $userId);
        if (!$row) {
            return $this->notFound();
        }
        $limit = $this->validator->checkBingoLimit($this->bingos->countByUser($userId));
        if ($limit !== null) {
            return $this->errorResponse($limit, 409);
        }
        $title = mb_substr($row['title'] . ' (copia)', 0, self::TITLE_MAX);
        $this->db->beginTransaction();
        try {
            $newId = $this->bingos->duplicate($id, $title);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
        return $this->successResponse('Bingo duplicado.', $this->dto($newId, $userId), 201);
    }

    /** Activa (token NUEVO cada vez: revoca los enlaces viejos) o desactiva el enlace público. */
    public function setShare(int $userId, array $data): array
    {
        $id = $this->intParam($data, 'bingo_id');
        if (!$this->bingos->findOwned($id, $userId)) {
            return $this->notFound();
        }
        $enabled = filter_var($data['enabled'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new InvalidArgumentException("Field 'enabled' must be true or false.");
        }
        $token = $enabled ? bin2hex(random_bytes(16)) : null;
        $this->bingos->setShareToken($id, $token);
        return $this->successResponse(
            $enabled ? 'Enlace activado.' : 'Enlace desactivado.',
            ['shareUrl' => BingoPresenter::shareUrl($token)]
        );
    }

    /**
     * Canciones de YouTube en lote: `{bingo_id, urls: string[≤50]}` →
     * `{added: ItemDTO[], failed: [{url, reason}]}`, en el orden de `urls`. Motivos de fallo:
     * `invalid_url` (YoutubeUrlParser), `duplicate` (ese vídeo ya está en el bingo o antes en el
     * lote), `not_found` / `not_embeddable` (oEmbed) y `unavailable` (oEmbed no contestó: reintentar).
     * oEmbed se llama sin transacción abierta; cada canción buena se inserta al momento, así que un
     * fallo a mitad no se lleva las anteriores.
     */
    public function addMusicItems(int $userId, array $data): array
    {
        $bingoId = $this->intParam($data, 'bingo_id');
        $urls = $data['urls'] ?? null;
        if (!is_array($urls) || !array_is_list($urls) || $urls === []) {
            throw new InvalidArgumentException("Field 'urls' must be a non-empty list.");
        }
        if (count($urls) > self::MUSIC_URLS_MAX) {
            throw new InvalidArgumentException('Como mucho ' . self::MUSIC_URLS_MAX . ' enlaces de una vez.');
        }
        if (!$this->bingos->findOwned($bingoId, $userId)) {
            return $this->notFound();
        }
        // Límite de 300 canciones por bingo: se mira con el lote entero, antes de llamar a oEmbed.
        $current = $this->bingos->countItems($bingoId, 'music');
        $full = $this->validator->checkItemLimit('music', $current);
        if ($full !== null) {
            return $this->errorResponse($full, 409);
        }
        $room = BingoValidator::MAX_ITEMS_PER_MODE - $current;
        if (count($urls) > $room) {
            return $this->errorResponse("En este bingo solo caben {$room} canciones más.", 409);
        }

        $seen = array_flip($this->bingos->musicVideoIds($bingoId));
        $added = [];
        $failed = [];
        foreach ($urls as $url) {
            $url = is_string($url) ? $url : '';
            $parsed = $this->youtube->parse($url);
            if ($parsed === null) {
                $failed[] = ['url' => $url, 'reason' => 'invalid_url'];
                continue;
            }
            $videoId = $parsed['videoId'];
            if (isset($seen[$videoId])) {
                $failed[] = ['url' => $url, 'reason' => 'duplicate'];
                continue;
            }
            $info = $this->oembed->lookup($videoId);
            if ($info['reason'] !== null) {
                $failed[] = ['url' => $url, 'reason' => $info['reason']];
                continue;
            }
            $seen[$videoId] = true;
            $label = $this->clip($info['title']) ?: 'Canción';
            $sublabel = $this->clip($info['authorName']) ?: null;
            $itemId = $this->bingos->insertMusicItem($bingoId, $label, $sublabel, $videoId, $parsed['startSeconds']);
            $added[] = $this->presenter->item($this->bingos->findItem($itemId));
        }
        if ($added) {
            $this->bingos->touch($bingoId);
        }
        $this->logger?->info('Music items added', [
            'user_id' => $userId, 'bingo_id' => $bingoId, 'added' => count($added), 'failed' => count($failed),
        ]);
        return $this->successResponse(
            count($added) === 1 ? '1 canción añadida.' : count($added) . ' canciones añadidas.',
            ['added' => $added, 'failed' => $failed]
        );
    }

    /**
     * `{item_id, label, sublabel?, start_seconds?}`. `start_seconds` solo vale para canciones: si no
     * viene, se conserva; null o '' lo quita (empieza en el 0).
     */
    public function updateItem(int $userId, array $data): array
    {
        $itemId = $this->intParam($data, 'item_id');
        $item = $this->bingos->findItemOwned($itemId, $userId);
        if (!$item) {
            return $this->errorResponse('Este elemento ya no existe.', 404);
        }
        $isMusic = $item['kind'] === 'music';
        $label = $this->text($data['label'] ?? null, self::ITEM_LABEL_MAX, $isMusic ? 'El título' : 'El texto de la foto');
        $sublabel = null;
        if (isset($data['sublabel']) && $data['sublabel'] !== '') {
            $sublabel = $this->text($data['sublabel'], self::ITEM_LABEL_MAX, 'El subtítulo');
        }
        $setStart = array_key_exists('start_seconds', $data);
        $start = null;
        if ($setStart) {
            if (!$isMusic) {
                throw new InvalidArgumentException('start_seconds solo vale para canciones.');
            }
            $start = $this->startSeconds($data['start_seconds']);
        }
        $this->bingos->updateItem($itemId, $label, $sublabel);
        if ($setStart) {
            $this->bingos->setItemStart($itemId, $start);
        }
        $this->bingos->touch((int) $item['bingo_id']);
        return $this->successResponse('Guardado.', $this->presenter->item($this->bingos->findItem($itemId)));
    }

    public function deleteItem(int $userId, array $data): array
    {
        $itemId = $this->intParam($data, 'item_id');
        $this->db->beginTransaction();
        try {
            $this->quota->lockAndCheck($userId, 0);
            $item = $this->bingos->findItemOwned($itemId, $userId);
            if (!$item) {
                $this->db->rollBack();
                return $this->errorResponse('Este elemento ya no existe.', 404);
            }
            $this->bingos->deleteItem($itemId);
            $this->bingos->touch((int) $item['bingo_id']);
            $orphans = $this->releaseOrphans($userId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->rollBackQuietly();
            throw $e;
        }
        $this->removeFiles($userId, $orphans);
        return $this->successResponse('Borrada.', ['storageBytes' => $this->quota->usedBytes($userId)]);
    }

    // ------------------------------------------------------------------ internos

    private function dto(int $bingoId, int $userId): array
    {
        $row = $this->bingos->findOwned($bingoId, $userId);
        return $this->presenter->bingo(
            $row,
            $this->bingos->items($bingoId),
            $this->bingos->printsCount($bingoId),
            $this->quota->usedBytes($userId)
        );
    }

    /** Estado del bingo con la forma que valida BingoValidator (cartón + nº de items por modo). */
    private function validationState(int $bingoId, array $row): array
    {
        $modes = ['numeric' => ['enabled' => (bool) $row['numeric_enabled']]];
        foreach (['music', 'image'] as $kind) {
            $modes[$kind] = [
                'enabled' => (bool) $row["{$kind}_enabled"],
                'card' => [(int) $row["{$kind}_rows"], (int) $row["{$kind}_cols"]],
                'count' => $this->bingos->countItems($bingoId, $kind),
            ];
        }
        return ['modes' => $modes];
    }

    private function numericPatch(mixed $mode): array
    {
        if (!is_array($mode)) {
            throw new InvalidArgumentException('modes.numeric debe ser un objeto.');
        }
        return array_key_exists('enabled', $mode) ? ['numeric_enabled' => $this->bool($mode['enabled'])] : [];
    }

    private function surprisePatch(string $kind, mixed $mode): array
    {
        if (!is_array($mode)) {
            throw new InvalidArgumentException("modes.{$kind} debe ser un objeto.");
        }
        $out = [];
        if (array_key_exists('enabled', $mode)) {
            $out["{$kind}_enabled"] = $this->bool($mode['enabled']);
        }
        if (array_key_exists('label', $mode)) {
            $out["{$kind}_label"] = $this->text($mode['label'], self::MODE_LABEL_MAX, 'El nombre del modo');
        }
        foreach (['rows', 'cols'] as $side) {
            if (array_key_exists($side, $mode)) {
                $n = filter_var($mode[$side], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 99]]);
                if ($n === false || is_bool($mode[$side])) {
                    throw new InvalidArgumentException('Filas y columnas deben ser números enteros.');
                }
                $out["{$kind}_{$side}"] = $n; // el rango 2..6 lo comprueba E3 si el modo está activo
            }
        }
        return $out;
    }

    /** @return array<int, array{id:int, sha256:string, bytes:int}> */
    private function releaseOrphans(int $userId): array
    {
        $orphans = $this->uploads->deleteOrphans($userId);
        $bytes = array_sum(array_column($orphans, 'bytes'));
        if ($bytes > 0) {
            $this->quota->release($userId, $bytes);
        }
        return $orphans;
    }

    private function removeFiles(int $userId, array $orphans): void
    {
        foreach ($orphans as $o) {
            if (!$this->storage->remove($userId, $o['sha256'])) {
                // La fila ya no existe: el fichero es basura sin dueño. Se avisa y se sigue.
                $this->logger?->error('Failed to remove upload files', ['user_id' => $userId, 'sha256' => $o['sha256']]);
            }
        }
    }

    private function title(mixed $value): string
    {
        return $this->text($value, self::TITLE_MAX, 'El título');
    }

    /** Texto obligatorio, recortado y de longitud acotada. */
    private function text(mixed $value, int $max, string $what): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException("{$what} es obligatorio.");
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        if ($value === '') {
            throw new InvalidArgumentException("{$what} es obligatorio.");
        }
        if (mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$what} no puede pasar de {$max} caracteres.");
        }
        return $value;
    }

    /** Texto de oEmbed → etiqueta: sin caracteres de control y recortado a 120. */
    private function clip(string $value): string
    {
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return mb_substr($value, 0, self::ITEM_LABEL_MAX);
    }

    private function startSeconds(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => YoutubeUrlParser::MAX_START_SECONDS],
        ]);
        if ($n === false || is_bool($value)) {
            throw new InvalidArgumentException('El inicio debe ser un número de segundos entre 0 y '
                . YoutubeUrlParser::MAX_START_SECONDS . '.');
        }
        return $n;
    }

    private function bool(mixed $value): bool
    {
        $b = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            throw new InvalidArgumentException('enabled debe ser true o false.');
        }
        return $b;
    }

    private function intParam(array $data, string $field): int
    {
        $n = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($n === false) {
            throw new InvalidArgumentException("Field '{$field}' is required.");
        }
        return $n;
    }

    private function notFound(): array
    {
        return $this->errorResponse('Este bingo no existe.', 404);
    }

    private function rollBackQuietly(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
