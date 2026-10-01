<?php

declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Forma del `PlayPayload`: lo que juega `apiBingo.js` y lo que se congela en `bingo_prints.snapshot`.
 *
 * PlayPayload = { id:'42', title,
 *                 modes: { numeric: {enabled, label:'Bingo'},
 *                          music:   {enabled, label, card:[r,c], items: BingoItem[]},
 *                          image:   {enabled, label, card:[r,c], items: BingoItem[]} },
 *                 plan: { leadIn, minGap, spreadOver } }
 * BingoItem   = { id:'i<itemId>', kind, label, sublabel,
 *                 media: { type:'image'|'youtube', url, thumbUrl, uploadId,
 *                          videoId, startSeconds, endSeconds } }
 *
 * Una canción va como `media: {type:'youtube', url:null, thumbUrl (i.ytimg.com), videoId,
 * startSeconds, endSeconds:null}`; su miniatura es pública y se recalcula al re-firmar.
 *
 * Las URLs de imagen van firmadas a 12 h (lo que dura una fiesta). El SNAPSHOT se guarda sin URLs
 * (`url`/`thumbUrl` a null) pero con el `uploadId`: al servirlo se re-firman solo los uploads que
 * siguen existiendo. Uno ya borrado se queda con las URLs a null; los cartones no lo notan, porque
 * se imprimen solo con textos y dependen únicamente del ORDEN y los ids de los items, que el
 * snapshot conserva.
 */
class PlayPresenter
{
    /** TTL de las URLs de una partida (`SignedUrlService::sign(…, 43200)`). */
    public const PLAY_URL_TTL = 43200;

    public function __construct(private readonly BingoPresenter $bingoPresenter)
    {
    }

    /**
     * PlayPayload con las URLs firmadas a 12 h.
     * @param array $row   fila de `bingos`
     * @param array $items filas de `BingoRepository::items()` (orden de inserción)
     */
    public function payload(array $row, array $items): array
    {
        $byKind = ['music' => [], 'image' => []];
        foreach ($items as $itemRow) {
            $dto = $this->bingoPresenter->item($itemRow, self::PLAY_URL_TTL);
            if (isset($byKind[$dto['kind']])) {
                $byKind[$dto['kind']][] = $this->bingoItem($dto);
            }
        }
        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['title'],
            'modes' => [
                'numeric' => [
                    'enabled' => (bool) $row['numeric_enabled'],
                    'label' => BingoPresenter::NUMERIC_LABEL,
                ],
                'music' => $this->surpriseMode($row, 'music', $byKind['music']),
                'image' => $this->surpriseMode($row, 'image', $byKind['image']),
            ],
            'plan' => [
                'leadIn' => (int) $row['lead_in'],
                'minGap' => (int) $row['min_gap'],
                'spreadOver' => (int) $row['spread_over'],
            ],
        ];
    }

    /** Copia del payload SIN URLs (caducarían): lo que se guarda en `bingo_prints.snapshot`. */
    public function snapshot(array $payload): array
    {
        return $this->mapItems($payload, function (array $item): array {
            $item['media']['url'] = null;
            $item['media']['thumbUrl'] = null;
            return $item;
        });
    }

    /**
     * Snapshot → PlayPayload servible: re-firma las fotos cuyo upload sigue vivo.
     * @param int[] $liveUploadIds uploads del dueño que aún existen
     */
    public function resign(array $snapshot, int $ownerId, array $liveUploadIds): array
    {
        $live = array_flip(array_map('intval', $liveUploadIds));
        return $this->mapItems($snapshot, function (array $item) use ($ownerId, $live): array {
            $uploadId = $item['media']['uploadId'] ?? null;
            $videoId = $item['media']['videoId'] ?? null;
            if (($item['media']['type'] ?? null) === 'youtube' && is_string($videoId)) {
                $item['media']['url'] = null;
                $item['media']['thumbUrl'] = YoutubeUrlParser::thumbUrl($videoId);
            } elseif ($uploadId !== null && isset($live[(int) $uploadId])) {
                $urls = $this->bingoPresenter->imageUrls((int) $uploadId, $ownerId, self::PLAY_URL_TTL);
                $item['media']['url'] = $urls['url'];
                $item['media']['thumbUrl'] = $urls['thumbUrl'];
            } else {
                $item['media']['url'] = null;
                $item['media']['thumbUrl'] = null;
            }
            return $item;
        });
    }

    /** `uploadId` de todas las fotos de un payload o snapshot. @return int[] */
    public function uploadIds(array $payload): array
    {
        $ids = [];
        foreach (['music', 'image'] as $kind) {
            foreach ($payload['modes'][$kind]['items'] ?? [] as $item) {
                if (($item['media']['uploadId'] ?? null) !== null) {
                    $ids[] = (int) $item['media']['uploadId'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /** Estado con la forma de `BingoValidator::validate` (E1–E4 sobre lo que se va a jugar). */
    public function validationState(array $payload): array
    {
        return ['modes' => $payload['modes']];
    }

    // ------------------------------------------------------------------ internos

    /** ItemDTO (BingoPresenter::item) → BingoItem del juego. */
    private function bingoItem(array $dto): array
    {
        $isImage = $dto['kind'] === 'image';
        return [
            'id' => 'i' . $dto['id'],
            'kind' => $dto['kind'],
            'label' => $dto['label'],
            'sublabel' => $dto['sublabel'],
            'media' => [
                'type' => $isImage ? 'image' : 'youtube',
                'url' => $dto['url'],
                'thumbUrl' => $dto['thumbUrl'],
                'uploadId' => $dto['uploadId'],
                'videoId' => $isImage ? null : $dto['videoId'],
                'startSeconds' => $isImage ? null : $dto['startSeconds'],
                'endSeconds' => null, // la entradilla corta sola a los 5 s; el esquema lo guarda, la UI no
            ],
        ];
    }

    private function surpriseMode(array $row, string $kind, array $items): array
    {
        return [
            'enabled' => (bool) $row["{$kind}_enabled"],
            'label' => (string) $row["{$kind}_label"],
            'card' => [(int) $row["{$kind}_rows"], (int) $row["{$kind}_cols"]],
            'items' => $items,
        ];
    }

    private function mapItems(array $payload, callable $fn): array
    {
        foreach (['music', 'image'] as $kind) {
            if (isset($payload['modes'][$kind]['items']) && is_array($payload['modes'][$kind]['items'])) {
                $payload['modes'][$kind]['items'] = array_map($fn, $payload['modes'][$kind]['items']);
            }
        }
        return $payload;
    }
}
