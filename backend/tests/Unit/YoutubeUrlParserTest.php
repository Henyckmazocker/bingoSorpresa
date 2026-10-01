<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Services\YoutubeUrlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Enlaces de YouTube del editor (plan de música, M1): las seis formas, los tiempos de inicio y lo
 * que NO es un vídeo (ids mal formados, otros dominios).
 */
class YoutubeUrlParserTest extends TestCase
{
    private YoutubeUrlParser $p;

    protected function setUp(): void
    {
        $this->p = new YoutubeUrlParser();
    }

    public static function seisFormas(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'watch sin www ni esquema' => ['youtube.com/watch?v=dQw4w9WgXcQ&list=PL123'],
            'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ'],
            'youtu.be con ?si=' => ['https://youtu.be/dQw4w9WgXcQ?si=AbCdEf'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
            'shorts' => ['https://youtube.com/shorts/dQw4w9WgXcQ'],
            'music' => ['https://music.youtube.com/watch?v=dQw4w9WgXcQ&feature=share'],
            'móvil' => ['http://m.youtube.com/watch?v=dQw4w9WgXcQ'],
        ];
    }

    #[DataProvider('seisFormas')]
    public function testLasSeisFormasDanElVideoId(string $url): void
    {
        $this->assertSame(['videoId' => 'dQw4w9WgXcQ', 'startSeconds' => null], $this->p->parse($url));
    }

    public static function tiempos(): array
    {
        return [
            't=90' => ['https://youtu.be/dQw4w9WgXcQ?t=90', 90],
            't=90s' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=90s', 90],
            't=1m30s' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1m30s', 90],
            't=1h2m3s' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=1h2m3s', 3723],
            'start=45 (embed)' => ['https://www.youtube.com/embed/dQw4w9WgXcQ?start=45', 45],
            '#t= en el fragmento' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ#t=2m', 120],
            't ilegible' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=abc', null],
            't fuera de rango' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=99999', null],
        ];
    }

    #[DataProvider('tiempos')]
    public function testLeeElInicio(string $url, ?int $seconds): void
    {
        $parsed = $this->p->parse($url);
        $this->assertNotNull($parsed, 'un tiempo ilegible no invalida el enlace');
        $this->assertSame('dQw4w9WgXcQ', $parsed['videoId']);
        $this->assertSame($seconds, $parsed['startSeconds']);
    }

    public static function invalidas(): array
    {
        return [
            'vacía' => [''],
            'id corto' => ['https://www.youtube.com/watch?v=dQw4w9WgXc'],
            'id largo' => ['https://youtu.be/dQw4w9WgXcQQ'],
            'id con caracteres raros' => ['https://www.youtube.com/watch?v=dQw4w9WgX%21Q'],
            'watch sin v' => ['https://www.youtube.com/watch?list=PL123'],
            'canal' => ['https://www.youtube.com/@rickastley'],
            'vimeo' => ['https://vimeo.com/76979871'],
            'dominio parecido' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
            'subdominio ajeno' => ['https://evil-youtube.com/watch?v=dQw4w9WgXcQ'],
            'shorts en music' => ['https://music.youtube.com/shorts/dQw4w9WgXcQ'],
            'esquema raro' => ['javascript://youtube.com/watch?v=dQw4w9WgXcQ'],
            'texto' => ['no es un enlace'],
        ];
    }

    #[DataProvider('invalidas')]
    public function testRechazaIdsInvalidosYDominiosAjenos(string $url): void
    {
        $this->assertNull($this->p->parse($url));
    }

    public function testMiniaturaCalculada(): void
    {
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', YoutubeUrlParser::thumbUrl('dQw4w9WgXcQ'));
    }
}
