<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Bingo\BingoRepositoryInterface;
use App\Domain\Repository\Print\PrintRepositoryInterface;
use App\Domain\Repository\Upload\UploadRepositoryInterface;
use App\Domain\Services\BingoValidator;
use App\Domain\Services\PlayPresenter;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Jugar e imprimir: el `PlayPayload` de un bingo (propio o por enlace compartido) y las tiradas
 * impresas (`bingo_prints`).
 *
 * - `get_play_payload`, `list_prints`, `get_print`: con cuenta y solo sobre bingos propios (404 si no).
 * - `get_shared_payload`: público por `share_token` (404 si el compartido está apagado).
 * - `save_print`: con `bingo_id` (cuenta + dueño) o con `share_token` (público; la tirada se
 *   asocia al bingo del token). Reimprimir por `print_id` exige cuenta: sin ella no hay tiradas.
 *
 * La semilla gobierna la IMPRESIÓN, no la partida: guardar la tirada congela el payload (sin URLs)
 * para que «Reimprimir» dé los mismos cartones aunque luego se borren o renombren fotos.
 */
class PlayController extends BaseController
{
    /** Mismo tope que el campo «Nº jugadores» de PrintView. */
    public const MAX_PLAYERS = 60;
    /** `bingo_prints.seed VARCHAR(64)`. */
    public const SEED_MAX = 64;

    public function __construct(
        private readonly BingoRepositoryInterface $bingos,
        private readonly UploadRepositoryInterface $uploads,
        private readonly PrintRepositoryInterface $prints,
        private readonly PlayPresenter $presenter,
        private readonly BingoValidator $validator,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getPlayPayload(int $userId, array $data): array
    {
        $row = $this->bingos->findOwned($this->intParam($data, 'bingo_id'), $userId);
        if (!$row) {
            return $this->notFound();
        }
        return $this->successResponse('OK', $this->payload($row));
    }

    public function getSharedPayload(array $data): array
    {
        $row = $this->sharedRow($data);
        if (!$row) {
            return $this->notFound();
        }
        return $this->successResponse('OK', $this->payload($row));
    }

    /** `{bingo_id|share_token, seed, players}` → `{printId}`. */
    public function savePrint(?int $userId, array $data, bool $shareAccess): array
    {
        if ($shareAccess) {
            $row = $this->sharedRow($data);
        } else {
            $row = $userId !== null ? $this->bingos->findOwned($this->intParam($data, 'bingo_id'), $userId) : null;
        }
        if (!$row) {
            return $this->notFound();
        }
        $seed = $this->seed($data['seed'] ?? null);
        $players = $this->players($data['players'] ?? null);

        $payload = $this->payload($row);
        $errors = $this->validator->validate($this->presenter->validationState($payload));
        if ($errors) {
            return [
                'status' => 'error',
                'message' => implode(' ', $errors),
                'data' => ['errors' => $errors],
                'http_code' => 400,
            ];
        }

        $printId = $this->prints->create((int) $row['id'], $seed, $players, $this->presenter->snapshot($payload));
        $this->logger?->info('Print saved', [
            'bingo_id' => (int) $row['id'],
            'print_id' => $printId,
            'players' => $players,
            'via' => $shareAccess ? 'share' : 'owner',
        ]);
        return $this->successResponse('Tirada guardada.', ['printId' => $printId], 201);
    }

    public function listPrints(int $userId, array $data): array
    {
        $bingoId = $this->intParam($data, 'bingo_id');
        if (!$this->bingos->findOwned($bingoId, $userId)) {
            return $this->notFound();
        }
        $rows = $this->prints->listByBingo($bingoId);
        return $this->successResponse('OK', array_map(fn ($r) => [
            'printId' => (int) $r['id'],
            'seed' => (string) $r['seed'],
            'players' => (int) $r['players'],
            'createdAt' => $r['created_at'],
        ], $rows));
    }

    /** El snapshot de la tirada con las URLs re-firmadas (las fotos ya borradas, sin URL). */
    public function getPrint(int $userId, array $data): array
    {
        $print = $this->prints->findOwned($this->intParam($data, 'print_id'), $userId);
        if (!$print) {
            return $this->errorResponse('Esta tirada no existe.', 404);
        }
        $snapshot = $print['snapshot'];
        $ownerId = (int) $print['user_id'];
        $live = $this->uploads->existingIds($ownerId, $this->presenter->uploadIds($snapshot));
        return $this->successResponse('OK', $this->presenter->resign($snapshot, $ownerId, $live));
    }

    // ------------------------------------------------------------------ internos

    private function payload(array $row): array
    {
        return $this->presenter->payload($row, $this->bingos->items((int) $row['id']));
    }

    /** Bingo del `share_token` (32 hex). Un token con otra forma no se busca: 404 sin más. */
    private function sharedRow(array $data): ?array
    {
        $token = $data['share_token'] ?? null;
        if (!is_string($token) || !preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        return $this->bingos->findShared($token);
    }

    /**
     * La semilla se guarda TAL CUAL (sin recortar): cambiar un espacio cambiaría los cartones.
     * Solo se exige que no esté vacía, que quepa en VARCHAR(64) y que no lleve caracteres de control.
     */
    private function seed(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('La semilla es obligatoria.');
        }
        if (mb_strlen($value) > self::SEED_MAX) {
            throw new InvalidArgumentException('La semilla no puede pasar de ' . self::SEED_MAX . ' caracteres.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('La semilla lleva caracteres no válidos.');
        }
        return $value;
    }

    private function players(mixed $value): int
    {
        $n = is_bool($value) ? false : filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAX_PLAYERS],
        ]);
        if ($n === false) {
            throw new InvalidArgumentException('El nº de jugadores debe estar entre 1 y ' . self::MAX_PLAYERS . '.');
        }
        return $n;
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
}
