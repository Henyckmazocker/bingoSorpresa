<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Repository\Bingo\BingoRepositoryInterface;
use App\Domain\Services\PairingService;
use App\Domain\Services\PlayPresenter;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * La tele por código (APK sin cuenta). Ver `PairingService` para el ciclo de vida.
 *
 * - `pair_create` (público)  → `{code, deviceToken, expiresIn:600}`
 * - `pair_poll {deviceToken}` (público) → `{status:'waiting'}` | `{status:'ready', payload}` · 410 caducado
 * - `pair_claim {code, bingo_id}` (cuenta, dueño del bingo) → `{}` · 404 código inexistente o
 *   caducado (o bingo ajeno) · 409 si el código ya tiene un bingo
 *
 * El payload de `ready` es el mismo que `get_play_payload` del dueño que reclamó (URLs a 12 h),
 * recalculado en cada sondeo: si el bingo se borra, el CASCADE se lleva el pairing y la tele ve 410.
 */
class PairingController extends BaseController
{
    public function __construct(
        private readonly PairingService $pairings,
        private readonly BingoRepositoryInterface $bingos,
        private readonly PlayPresenter $presenter,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function create(): array
    {
        return $this->successResponse('OK', $this->pairings->create(), 201);
    }

    public function poll(array $data): array
    {
        $token = $data['deviceToken'] ?? null;
        if (!PairingService::isDeviceToken($token)) {
            throw new InvalidArgumentException("Field 'deviceToken' is required.");
        }
        $state = $this->pairings->poll($token);
        if ($state === null) {
            return $this->expired();
        }
        if ($state['bingoId'] === null) {
            return $this->successResponse('OK', ['status' => 'waiting']);
        }
        $row = $this->bingos->findOwned($state['bingoId'], (int) $state['ownerId']);
        if (!$row) {
            return $this->expired();
        }
        $payload = $this->presenter->payload($row, $this->bingos->items((int) $row['id']));
        return $this->successResponse('OK', ['status' => 'ready', 'payload' => $payload]);
    }

    public function claim(int $userId, array $data): array
    {
        $code = $data['code'] ?? null;
        if (is_int($code)) {
            $code = str_pad((string) $code, 6, '0', STR_PAD_LEFT);
        }
        if (!PairingService::isCode($code)) {
            return $this->errorResponse('El código son 6 cifras.', 400);
        }
        $bingoId = filter_var($data['bingo_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($bingoId === false) {
            throw new InvalidArgumentException("Field 'bingo_id' is required.");
        }
        if (!$this->bingos->findOwned($bingoId, $userId)) {
            return $this->errorResponse('Este bingo no existe.', 404);
        }

        $result = $this->pairings->claim($code, $bingoId, $userId);
        if ($result === PairingService::CLAIM_NOT_FOUND) {
            return $this->errorResponse('Ese código no existe o ha caducado. Mira el de la tele.', 404);
        }
        if ($result === PairingService::CLAIM_TAKEN) {
            return $this->errorResponse('Esa tele ya ha recibido un bingo. Mira el código nuevo de la tele.', 409);
        }
        $this->logger?->info('TV paired', ['bingo_id' => $bingoId, 'user_id' => $userId]);
        return [
            'status' => 'success',
            'message' => 'Bingo enviado a la tele.',
            'data' => new \stdClass(), // {} y no []: contrato de la API
            'http_code' => 200,
        ];
    }

    private function expired(): array
    {
        return $this->errorResponse('El código ha caducado.', 410);
    }
}
