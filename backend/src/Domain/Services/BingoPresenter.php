<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Infrastructure\Auth\SignedUrlService;

/**
 * Forma de la API de un bingo (filas de BD → `BingoDTO` / `ItemDTO` / resumen de `list_bingos`).
 *
 * BingoDTO = { id, title, shared, shareUrl, updatedAt, printsCount, storageBytes, storageQuota,
 *              modes: { numeric: {enabled, label:'Bingo'},
 *                       music:   {enabled, label, rows, cols},
 *                       image:   {enabled, label, rows, cols} },
 *              plan: { leadIn, minGap, spreadOver },
 *              items: ItemDTO[] }                        // orden de inserción
 * ItemDTO  = { id, kind, label, sublabel, uploadId, width, height, url, thumbUrl, videoId, startSeconds }
 *
 * Las URLs de imagen van firmadas con `SignedUrlService::sign('img', uploadId, ownerId, ttl)`. Una
 * canción de YouTube no tiene `url` y su `thumbUrl` es la miniatura pública de i.ytimg.com
 * (calculada, no guardada).
 */
class BingoPresenter
{
    /** TTL de las URLs del editor (1 h). Las 12 h son del PlayPayload de la partida. */
    public const EDITOR_URL_TTL = 3600;

    /** Etiqueta fija del modo numérico (como en la fiesta de casa y el PlayPayload). */
    public const NUMERIC_LABEL = 'Bingo';

    public function __construct(private readonly SignedUrlService $signer)
    {
    }

    public function bingo(array $row, array $items, int $printsCount, int $storageBytes): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'shared' => $row['share_token'] !== null,
            'shareUrl' => self::shareUrl($row['share_token']),
            'updatedAt' => $row['updated_at'],
            'printsCount' => $printsCount,
            'storageBytes' => $storageBytes,
            'storageQuota' => QuotaService::QUOTA_BYTES,
            'modes' => [
                'numeric' => ['enabled' => (bool) $row['numeric_enabled'], 'label' => self::NUMERIC_LABEL],
                'music' => $this->surpriseMode($row, 'music'),
                'image' => $this->surpriseMode($row, 'image'),
            ],
            'plan' => [
                'leadIn' => (int) $row['lead_in'],
                'minGap' => (int) $row['min_gap'],
                'spreadOver' => (int) $row['spread_over'],
            ],
            'items' => array_map(fn ($i) => $this->item($i), $items),
        ];
    }

    public function item(array $row, int $ttl = self::EDITOR_URL_TTL): array
    {
        $uploadId = $row['upload_id'] !== null ? (int) $row['upload_id'] : null;
        $videoId = isset($row['youtube_video_id']) ? (string) $row['youtube_video_id'] : null;
        ['url' => $url, 'thumbUrl' => $thumbUrl] = match (true) {
            $uploadId !== null => $this->imageUrls($uploadId, (int) $row['user_id'], $ttl),
            $videoId !== null => ['url' => null, 'thumbUrl' => YoutubeUrlParser::thumbUrl($videoId)],
            default => ['url' => null, 'thumbUrl' => null],
        };
        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'label' => (string) $row['label'],
            'sublabel' => $row['sublabel'],
            'uploadId' => $uploadId,
            'width' => isset($row['width']) ? (int) $row['width'] : null,
            'height' => isset($row['height']) ? (int) $row['height'] : null,
            'url' => $url,
            'thumbUrl' => $thumbUrl,
            'videoId' => $videoId,
            'startSeconds' => isset($row['start_seconds']) ? (int) $row['start_seconds'] : null,
        ];
    }

    /**
     * URLs firmadas de un upload (foto y miniatura). También las usa `PlayPresenter` para re-firmar
     * los snapshots de `bingo_prints`.
     * @return array{url:string, thumbUrl:string}
     */
    public function imageUrls(int $uploadId, int $ownerId, int $ttl): array
    {
        $url = '/img.php?t=' . rawurlencode($this->signer->sign('img', $uploadId, $ownerId, $ttl));
        return ['url' => $url, 'thumbUrl' => $url . '&thumb=1'];
    }

    /** Fila de `list_bingos` (con music_count, image_count y prints_count). */
    public function summary(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'numericEnabled' => (bool) $row['numeric_enabled'],
            'musicEnabled' => (bool) $row['music_enabled'],
            'imageEnabled' => (bool) $row['image_enabled'],
            'musicCount' => (int) $row['music_count'],
            'imageCount' => (int) $row['image_count'],
            'printsCount' => (int) $row['prints_count'],
            'shared' => $row['share_token'] !== null,
            'updatedAt' => $row['updated_at'],
        ];
    }

    /** Enlace público relativo al origen (la vista `/b/:token` es del hito de jugar). */
    public static function shareUrl(?string $token): ?string
    {
        return $token === null ? null : '/#/b/' . $token;
    }

    private function surpriseMode(array $row, string $kind): array
    {
        return [
            'enabled' => (bool) $row["{$kind}_enabled"],
            'label' => (string) $row["{$kind}_label"],
            'rows' => (int) $row["{$kind}_rows"],
            'cols' => (int) $row["{$kind}_cols"],
        ];
    }
}
