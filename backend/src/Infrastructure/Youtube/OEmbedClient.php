<?php

declare(strict_types=1);

namespace App\Infrastructure\Youtube;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * oEmbed público de YouTube: título y canal de un vídeo, y de paso si se puede incrustar. Sin clave
 * ni cuota (la Data API v3 queda fuera del plan).
 *
 *   GET https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=<id>&format=json  (5 s)
 *   200       → {title, author_name}
 *   401 / 403 → not_embeddable (el dueño desactivó la incrustación)
 *   404 / 400 → not_found. Con ids inventados YouTube contesta 400 casi siempre y 404 solo a veces;
 *               el id ya llega validado por YoutubeUrlParser, así que 400 = «no lo conoce».
 *   lo demás (5xx, timeout, JSON roto) → unavailable: no se sabe, que el usuario lo reintente.
 */
class OEmbedClient
{
    public const NOT_FOUND = 'not_found';
    public const NOT_EMBEDDABLE = 'not_embeddable';
    public const UNAVAILABLE = 'unavailable';

    private const ENDPOINT = 'https://www.youtube.com/oembed';
    private const TIMEOUT = 5;

    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null)
    {
        $this->http = $http ?? new Client();
    }

    /**
     * @return array{reason:null, title:string, authorName:string}|array{reason:string}
     *         `reason` null = vídeo incrustable; si no, NOT_FOUND / NOT_EMBEDDABLE / UNAVAILABLE.
     */
    public function lookup(string $videoId): array
    {
        try {
            $res = $this->http->request('GET', self::ENDPOINT, [
                'query' => ['url' => 'https://www.youtube.com/watch?v=' . $videoId, 'format' => 'json'],
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::TIMEOUT,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GuzzleException) {
            return ['reason' => self::UNAVAILABLE];
        }

        $code = $res->getStatusCode();
        if ($code === 401 || $code === 403) {
            return ['reason' => self::NOT_EMBEDDABLE];
        }
        if ($code === 404 || $code === 400) {
            return ['reason' => self::NOT_FOUND];
        }
        if ($code !== 200) {
            return ['reason' => self::UNAVAILABLE];
        }
        $json = json_decode((string) $res->getBody(), true);
        if (!is_array($json) || !isset($json['title']) || !is_string($json['title'])) {
            return ['reason' => self::UNAVAILABLE];
        }
        return [
            'reason' => null,
            'title' => $json['title'],
            'authorName' => is_string($json['author_name'] ?? null) ? $json['author_name'] : '',
        ];
    }
}
