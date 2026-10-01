<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Services\BingoPresenter;
use App\Domain\Services\PlayPresenter;
use App\Infrastructure\Auth\SignedUrlService;
use PHPUnit\Framework\TestCase;

/**
 * PlayPayload, snapshot de `bingo_prints` y re-firmado al servir una tirada (M4). La prueba que
 * importa: el snapshot conserva TODOS los items en su orden aunque un upload ya no exista.
 */
class PlayPresenterTest extends TestCase
{
    private PlayPresenter $p;
    private SignedUrlService $signer;

    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-de-al-menos-32-caracteres!!';
        $this->signer = new SignedUrlService();
        $this->p = new PlayPresenter(new BingoPresenter($this->signer));
    }

    private function bingoRow(): array
    {
        return [
            'id' => 42, 'user_id' => 7, 'title' => 'Cumple', 'share_token' => null,
            'numeric_enabled' => 1,
            'music_enabled' => 0, 'music_label' => 'Bingo Musical', 'music_rows' => 3, 'music_cols' => 3,
            'image_enabled' => 1, 'image_label' => 'Bingo de Fotos', 'image_rows' => 2, 'image_cols' => 2,
            'lead_in' => 15, 'min_gap' => 4, 'spread_over' => 55,
        ];
    }

    private function itemRows(int $n): array
    {
        return array_map(fn ($i) => [
            'id' => 100 + $i, 'bingo_id' => 42, 'user_id' => 7, 'kind' => 'image',
            'label' => "Foto {$i}", 'sublabel' => null, 'upload_id' => 500 + $i,
            'width' => 800, 'height' => 600,
        ], range(1, $n));
    }

    public function testPayloadTieneLaFormaDeBingoConfigConUrlsDe12h(): void
    {
        $payload = $this->p->payload($this->bingoRow(), $this->itemRows(5));

        $this->assertSame('42', $payload['id']);
        $this->assertSame(['enabled' => true, 'label' => 'Bingo'], $payload['modes']['numeric']);
        $this->assertSame([2, 2], $payload['modes']['image']['card']);
        $this->assertSame([], $payload['modes']['music']['items']);
        $this->assertSame(['leadIn' => 15, 'minGap' => 4, 'spreadOver' => 55], $payload['plan']);

        $items = $payload['modes']['image']['items'];
        $this->assertCount(5, $items);
        $this->assertSame('i101', $items[0]['id']);
        $this->assertSame('image', $items[0]['media']['type']);
        $this->assertSame(501, $items[0]['media']['uploadId']);
        $this->assertStringEndsWith('&thumb=1', $items[0]['media']['thumbUrl']);

        parse_str((string) parse_url($items[0]['media']['url'], PHP_URL_QUERY), $q);
        $token = $this->signer->verify($q['t']);
        $this->assertSame(['k' => 'img', 'id' => 501, 'uid' => 7], array_intersect_key($token, ['k' => 1, 'id' => 1, 'uid' => 1]));
        $this->assertEqualsWithDelta(time() + 43200, $token['exp'], 5);
    }

    public function testElSnapshotNoGuardaUrlsPeroSiLosUploads(): void
    {
        $snapshot = $this->p->snapshot($this->p->payload($this->bingoRow(), $this->itemRows(3)));
        foreach ($snapshot['modes']['image']['items'] as $item) {
            $this->assertNull($item['media']['url']);
            $this->assertNull($item['media']['thumbUrl']);
        }
        $this->assertSame([501, 502, 503], $this->p->uploadIds($snapshot));
    }

    public function testResignDejaSinUrlLosUploadsBorradosYConservaOrdenYTextos(): void
    {
        $payload = $this->p->payload($this->bingoRow(), $this->itemRows(4));
        $snapshot = $this->p->snapshot($payload);

        // El upload 502 ya no existe (se borró su foto).
        $served = $this->p->resign($snapshot, 7, [501, 503, 504]);
        $items = $served['modes']['image']['items'];

        $this->assertSame(
            array_map(fn ($i) => [$i['id'], $i['label']], $payload['modes']['image']['items']),
            array_map(fn ($i) => [$i['id'], $i['label']], $items)
        );
        $this->assertNull($items[1]['media']['url']);
        $this->assertNull($items[1]['media']['thumbUrl']);
        $this->assertNotNull($items[0]['media']['url']);
        $this->assertNotNull($this->signer->verify(substr($items[3]['media']['url'], strlen('/img.php?t='))));
    }

    public function testValidationStateCuentaLosItemsComoBingoValidator(): void
    {
        $v = new \App\Domain\Services\BingoValidator();
        $ok = $this->p->payload($this->bingoRow(), $this->itemRows(4));
        $this->assertSame([], $v->validate($this->p->validationState($ok)));

        $short = $this->p->payload($this->bingoRow(), $this->itemRows(3));
        $this->assertSame(
            ['El cartón de fotos tiene 4 casillas y solo hay 3 fotos.'],
            $v->validate($this->p->validationState($short))
        );
    }

    public function testLasCancionesSalenComoYoutubeConMiniaturaYSobrevivenAlSnapshot(): void
    {
        $music = [
            'id' => 200, 'bingo_id' => 42, 'user_id' => 7, 'kind' => 'music',
            'label' => 'Never Gonna Give You Up', 'sublabel' => 'Rick Astley', 'upload_id' => null,
            'youtube_video_id' => 'dQw4w9WgXcQ', 'start_seconds' => 43, 'end_seconds' => null,
        ];
        $payload = $this->p->payload($this->bingoRow(), array_merge($this->itemRows(2), [$music]));
        $song = $payload['modes']['music']['items'][0];

        $this->assertSame('i200', $song['id']);
        $this->assertSame([
            'type' => 'youtube', 'url' => null,
            'thumbUrl' => 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
            'uploadId' => null, 'videoId' => 'dQw4w9WgXcQ', 'startSeconds' => 43, 'endSeconds' => null,
        ], $song['media']);
        $this->assertNull($payload['modes']['image']['items'][0]['media']['videoId']);

        $served = $this->p->resign($this->p->snapshot($payload), 7, [501, 502]);
        $this->assertSame($song['media'], $served['modes']['music']['items'][0]['media']);
        $this->assertSame([501, 502], $this->p->uploadIds($payload));
    }
}
