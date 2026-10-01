<?php

declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Enlace de YouTube pegado en el editor → `videoId` (+ segundo de inicio, si lo trae).
 *
 * Seis formas, con o sin `https://` y con o sin `www.`:
 *   youtube.com/watch?v=ID · youtu.be/ID · youtube.com/embed/ID · youtube.com/shorts/ID ·
 *   music.youtube.com/watch?v=ID · m.youtube.com/watch?v=ID
 * El inicio sale de `t=` o `start=` (query o fragmento), en segundos (`90`, `90s`) o con el formato
 * `1m30s` / `1h2m3s`. Un tiempo ilegible no invalida el enlace: se queda sin inicio.
 *
 * Solo mira la forma: si el vídeo existe y se puede incrustar lo dice `OEmbedClient`.
 */
class YoutubeUrlParser
{
    private const VIDEO_ID = '/^[A-Za-z0-9_-]{11}$/';

    /** Tope de `bingo_items.start_seconds` (SMALLINT UNSIGNED). */
    public const MAX_START_SECONDS = 65535;

    private const WATCH_HOSTS = ['youtube.com', 'm.youtube.com', 'music.youtube.com'];

    /** @return array{videoId:string, startSeconds:?int}|null null si no es un enlace de vídeo válido */
    public function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url; // «youtu.be/…» pegado sin esquema
        }
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        parse_str($parts['fragment'] ?? '', $fragment);

        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', ltrim($path, '/'))[0];
        } elseif (in_array($host, self::WATCH_HOSTS, true)) {
            if (rtrim($path, '/') === '/watch') {
                $id = $query['v'] ?? null;
            } elseif ($host === 'youtube.com' && preg_match('#^/(embed|shorts)/([^/]+)/?$#', $path, $m)) {
                $id = $m[2];
            }
        }
        if (!is_string($id) || !preg_match(self::VIDEO_ID, $id)) {
            return null;
        }

        $start = null;
        foreach ([$query, $fragment] as $params) {
            foreach (['t', 'start'] as $key) {
                if ($start === null && isset($params[$key]) && is_string($params[$key])) {
                    $start = $this->seconds($params[$key]);
                }
            }
        }
        return ['videoId' => $id, 'startSeconds' => $start];
    }

    /** «90», «90s», «1m30s», «1h2m3s» → segundos; null si no se entiende o se pasa del tope. */
    public function seconds(string $value): ?int
    {
        $value = strtolower(trim($value));
        if (preg_match('/^\d+$/', $value)) {
            $n = (int) $value;
        } elseif ($value !== '' && preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $m)) {
            $n = (int) ($m[1] ?? 0) * 3600 + (int) ($m[2] ?? 0) * 60 + (int) ($m[3] ?? 0);
        } else {
            return null;
        }
        return $n <= self::MAX_START_SECONDS ? $n : null;
    }

    /** Miniatura pública de YouTube: se calcula, no se guarda. */
    public static function thumbUrl(string $videoId): string
    {
        return 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
    }
}
