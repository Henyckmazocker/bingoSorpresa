<?php

declare(strict_types=1);

namespace App\Domain\Repository\Print;

/**
 * Tiradas impresas (`bingo_prints`): semilla, nº de jugadores y el `PlayPayload` congelado (sin
 * URLs) del momento de imprimir. Con eso, reimprimir da los MISMOS cartones aunque el bingo cambie.
 */
interface PrintRepositoryInterface
{
    /** @param array $snapshot PlayPayload sin URLs (PlayPresenter::snapshot) */
    public function create(int $bingoId, string $seed, int $players, array $snapshot): int;

    /**
     * Tiradas del bingo, de la más antigua a la más reciente (sin el snapshot).
     * @return array<int, array{id:int, seed:string, players:int, created_at:string}>
     */
    public function listByBingo(int $bingoId): array;

    /**
     * Tirada con su snapshot decodificado y el `user_id` del bingo, si el bingo es del usuario;
     * null si no existe o es de otro.
     */
    public function findOwned(int $printId, int $userId): ?array;
}
