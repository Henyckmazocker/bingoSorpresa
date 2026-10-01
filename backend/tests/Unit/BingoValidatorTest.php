<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Services\BingoValidator;
use PHPUnit\Framework\TestCase;

/**
 * Mismos casos que `frontend/tests/unit/validateConfig.test.js` (gemelo JS de BingoValidator), con
 * los mismos mensajes, más los límites de la apertura (30 bingos, 300 items).
 */
class BingoValidatorTest extends TestCase
{
    private BingoValidator $v;

    protected function setUp(): void
    {
        $this->v = new BingoValidator();
    }

    /** Equivalente de `loadLocalBingo()`: numérico + música 3×3 (58) + fotos 4×5 (86). */
    private function localConfig(): array
    {
        return ['modes' => [
            'numeric' => ['enabled' => true],
            'music' => ['enabled' => true, 'card' => [3, 3], 'count' => 58],
            'image' => ['enabled' => true, 'card' => [4, 5], 'count' => 86],
        ]];
    }

    public function testLaConfigLocalEsValida(): void
    {
        $this->assertSame([], $this->v->validate($this->localConfig()));
    }

    public function testLimitesDelCartonSon2a6(): void
    {
        $this->assertSame(2, BingoValidator::CARD_MIN_SIDE);
        $this->assertSame(6, BingoValidator::CARD_MAX_SIDE);
    }

    public function testE1TodosLosModosApagados(): void
    {
        $c = $this->localConfig();
        $c['modes']['numeric']['enabled'] = false;
        $c['modes']['music']['enabled'] = false;
        $c['modes']['image']['enabled'] = false;
        $this->assertSame(['Activa al menos un modo de juego.'], $this->v->validate($c));
    }

    public function testE2ModoSorpresaActivoSinItemsYApagadoSinItemsEsValido(): void
    {
        $c = $this->localConfig();
        $c['modes']['music']['count'] = 0;
        $this->assertSame(['El modo de canciones está activo y tiene 0 canciones.'], $this->v->validate($c));
        $c['modes']['music']['enabled'] = false;
        $this->assertSame([], $this->v->validate($c));
    }

    public function testE3CartonFueraDe2a6(): void
    {
        $c = $this->localConfig();
        $c['modes']['image']['card'] = [7, 3];
        $this->assertSame(
            ['El cartón de fotos mide 7×3; filas y columnas deben estar entre 2 y 6.'],
            $this->v->validate($c)
        );
    }

    public function testE4MasCasillasQueItems(): void
    {
        $c = $this->localConfig();
        $c['modes']['image']['count'] = 12;
        $this->assertSame(['El cartón de fotos tiene 20 casillas y solo hay 12 fotos.'], $this->v->validate($c));
    }

    /** El caso del «Hecho cuando» de M3: 25 fotos, 4×5 vale y 6×6 da E4. */
    public function testE4ConVeinticincoFotos(): void
    {
        $c = ['modes' => [
            'numeric' => ['enabled' => true],
            'music' => ['enabled' => false, 'card' => [3, 3], 'count' => 0],
            'image' => ['enabled' => true, 'card' => [4, 5], 'count' => 25],
        ]];
        $this->assertSame([], $this->v->validate($c));
        $c['modes']['image']['card'] = [6, 6];
        $this->assertSame(['El cartón de fotos tiene 36 casillas y solo hay 25 fotos.'], $this->v->validate($c));
    }

    public function testItemsComoArrayIgualQueEnElJs(): void
    {
        $c = $this->localConfig();
        unset($c['modes']['image']['count']);
        $c['modes']['image']['items'] = array_fill(0, 12, ['id' => 'x']);
        $this->assertSame(['El cartón de fotos tiene 20 casillas y solo hay 12 fotos.'], $this->v->validate($c));
    }

    public function testLimiteDeTreintaBingos(): void
    {
        $this->assertNull($this->v->checkBingoLimit(29));
        $this->assertSame(
            'Ya tienes 30 bingos, el máximo. Borra alguno para crear otro.',
            $this->v->checkBingoLimit(30)
        );
    }

    public function testLimiteDeTrescientosItems(): void
    {
        $this->assertNull($this->v->checkItemLimit('image', 299));
        $this->assertSame('Este bingo ya tiene 300 fotos, el máximo.', $this->v->checkItemLimit('image', 300));
    }
}
