<?php

declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Reglas de un bingo del backoffice. Gemela PHP de `frontend/src/bingo/validateConfig.js`: las
 * reglas E1–E4 son LAS MISMAS y con LOS MISMOS mensajes (si cambias una, cambia la otra). Además,
 * los límites de la apertura pública: 30 bingos por usuario y 300 items por modo y bingo.
 *
 * Trabaja sobre el estado RESULTANTE de un bingo (lo que quedaría guardado tras aplicar el patch),
 * con la forma de un `BingoConfig` reducido:
 *   ['modes' => ['numeric' => ['enabled' => bool],
 *                'music'   => ['enabled' => bool, 'card' => [filas, columnas], 'count' => int],
 *                'image'   => ['enabled' => bool, 'card' => [filas, columnas], 'count' => int]]]
 * `count` es el nº de items del modo; en su lugar vale `items` (array), como en el JS.
 */
class BingoValidator
{
    /** Lados admitidos para un cartón sorpresa (= `CARD_LIMITS` de validateConfig.js). */
    public const CARD_MIN_SIDE = 2;
    public const CARD_MAX_SIDE = 6;

    /** Límites de la apertura pública (plan, «Límites»). */
    public const MAX_BINGOS_PER_USER = 30;
    public const MAX_ITEMS_PER_MODE = 300;

    public const MODE_KINDS = ['numeric', 'music', 'image'];
    private const SURPRISE_KINDS = ['music', 'image'];
    private const ITEM_NOUN = ['music' => 'canciones', 'image' => 'fotos'];

    /**
     * E1–E4. Devuelve la lista de errores (vacía = válido), en el mismo orden que el JS.
     *
     * @return string[]
     */
    public function validate(array $config): array
    {
        $errors = [];
        $modes = is_array($config['modes'] ?? null) ? $config['modes'] : [];

        // E1 — al menos un modo activo. (Gemela: validateConfig.js, E1.)
        $anyEnabled = false;
        foreach (self::MODE_KINDS as $kind) {
            if (!empty($modes[$kind]['enabled'])) {
                $anyEnabled = true;
                break;
            }
        }
        if (!$anyEnabled) {
            $errors[] = 'Activa al menos un modo de juego.';
        }

        // E2–E4 solo para modos sorpresa ACTIVOS: uno desactivado puede estar vacío.
        foreach (self::SURPRISE_KINDS as $kind) {
            $mode = $modes[$kind] ?? null;
            if (!is_array($mode) || empty($mode['enabled'])) {
                continue;
            }
            $noun = self::ITEM_NOUN[$kind];
            $count = $this->itemCount($mode);

            // E2 — modo sorpresa activo → al menos 1 item.
            if ($count < 1) {
                $errors[] = "El modo de {$noun} está activo y tiene 0 {$noun}.";
            }

            // E3 — cartón [filas, columnas] con lados entre 2 y 6.
            $card = $mode['card'] ?? null;
            $shapeOk = is_array($card) && count($card) === 2
                && array_is_list($card)
                && $this->isValidSide($card[0]) && $this->isValidSide($card[1]);
            if (!$shapeOk) {
                $shown = is_array($card)
                    ? implode('×', array_map([$this, 'show'], $card))
                    : $this->show($card);
                $errors[] = "El cartón de {$noun} mide {$shown}; filas y columnas deben estar entre "
                    . self::CARD_MIN_SIDE . ' y ' . self::CARD_MAX_SIDE . '.';
            }

            // E4 — filas×columnas ≤ items. Solo con cartón válido y algún item (si no, E2/E3).
            if ($shapeOk && $count >= 1) {
                $cells = $card[0] * $card[1];
                if ($cells > $count) {
                    $errors[] = "El cartón de {$noun} tiene {$cells} casillas y solo hay {$count} {$noun}.";
                }
            }
        }

        return $errors;
    }

    /** Límite de bingos: null si cabe uno más, o el mensaje (la API responde 409). */
    public function checkBingoLimit(int $currentBingos): ?string
    {
        if ($currentBingos >= self::MAX_BINGOS_PER_USER) {
            return 'Ya tienes ' . self::MAX_BINGOS_PER_USER . ' bingos, el máximo. Borra alguno para crear otro.';
        }
        return null;
    }

    /** Límite de items de un modo: null si cabe uno más, o el mensaje de validación. */
    public function checkItemLimit(string $kind, int $currentItems): ?string
    {
        if ($currentItems >= self::MAX_ITEMS_PER_MODE) {
            $noun = self::ITEM_NOUN[$kind] ?? 'items';
            return 'Este bingo ya tiene ' . self::MAX_ITEMS_PER_MODE . " {$noun}, el máximo.";
        }
        return null;
    }

    private function itemCount(array $mode): int
    {
        if (isset($mode['count'])) {
            return (int) $mode['count'];
        }
        return is_array($mode['items'] ?? null) ? count($mode['items']) : 0;
    }

    private function isValidSide(mixed $n): bool
    {
        return is_int($n) && $n >= self::CARD_MIN_SIDE && $n <= self::CARD_MAX_SIDE;
    }

    /** Como `String(x)` en JS para los valores que pueden llegar (para el texto de E3). */
    private function show(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v) => (string) $v,
            default => json_encode($v) ?: '?',
        };
    }
}
