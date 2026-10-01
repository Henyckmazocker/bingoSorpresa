<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Exceptions\ImageRejectedException;
use App\Domain\Services\ImageProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Guardas y reencodado de ImageProcessor (ver «El riesgo que manda» del plan). Las imágenes de
 * prueba se generan con GD o a mano (cabeceras PNG/EXIF), sin ficheros binarios en el repo.
 */
class ImageProcessorTest extends TestCase
{
    private ImageProcessor $p;
    /** @var string[] */
    private array $tmp = [];

    protected function setUp(): void
    {
        $this->p = new ImageProcessor();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
    }

    private function file(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imgp');
        file_put_contents($path, $bytes);
        $this->tmp[] = $path;
        return $path;
    }

    private function gd(int $w, int $h, string $format): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, intdiv($w, 2), $h, imagecolorallocate($img, 255, 0, 0));
        ob_start();
        match ($format) {
            'jpeg' => imagejpeg($img, null, 90),
            'png' => imagepng($img),
            'gif' => imagegif($img),
            'webp' => imagewebp($img),
        };
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** Código HTTP con el que se rechaza el fichero (guardas + decodificación), o 0 si pasa. */
    private function rejection(string $path, int $error = UPLOAD_ERR_OK): int
    {
        try {
            $info = $this->p->inspect($path, $error);
            $this->p->process($path, $info);
            return 0;
        } catch (ImageRejectedException $e) {
            return $e->getHttpCode();
        }
    }

    // ------------------------------------------------------------------ rechazos

    public function testRechazaSvg(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            . '<script>alert(1)</script><rect width="10" height="10"/></svg>';
        $this->assertSame(415, $this->rejection($this->file($svg)));
    }

    /** 8000×6000 = 48 Mpx: se rechaza por la cabecera, sin decodificar (no hay datos de imagen). */
    public function testRechazaPngDe8000x6000SinDecodificarlo(): void
    {
        $ihdr = pack('NNCCCCC', 8000, 6000, 8, 6, 0, 0, 0);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data
            . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', '') . $chunk('IEND', '');

        $path = $this->file($png);
        $this->assertSame([8000, 6000], array_slice(getimagesize($path), 0, 2));
        try {
            $this->p->inspect($path);
            $this->fail('Debería rechazarse en inspect(), antes de GD');
        } catch (ImageRejectedException $e) {
            $this->assertSame(422, $e->getHttpCode());
        }
    }

    public function testRechazaFicheroTruncado(): void
    {
        $png = $this->gd(400, 300, 'png');
        // Cortado a la mitad: la cabecera se lee, pero los datos no se pueden decodificar.
        $this->assertSame(422, $this->rejection($this->file(substr($png, 0, intdiv(strlen($png), 2)))));
        // Cortado dentro de la cabecera: ni getimagesize puede.
        $this->assertSame(415, $this->rejection($this->file(substr($png, 0, 20))));
    }

    public function testRechazaJpegConPhpAnadidoAlFinal(): void
    {
        $jpeg = $this->gd(64, 48, 'jpeg') . '<?php system($_GET["c"]); ?>';
        $this->assertSame(415, $this->rejection($this->file($jpeg)));
    }

    public function testRechazaMasDe10MbYErroresDeSubida(): void
    {
        $this->assertSame(413, $this->rejection($this->file(str_repeat("\0", ImageProcessor::MAX_BYTES + 1))));
        $this->assertSame(413, $this->rejection('/nonexistent', UPLOAD_ERR_INI_SIZE));
        $this->assertSame(400, $this->rejection('/nonexistent', UPLOAD_ERR_PARTIAL));
    }

    // ------------------------------------------------------------------ reencodado

    /** JPEG con un bloque EXIF (Orientation=6, «girar 90° a la derecha») hecho a mano. */
    private function jpegWithExif(int $w, int $h): string
    {
        $tiff = "II\x2A\x00" . pack('V', 8)        // cabecera TIFF little-endian, IFD0 en el offset 8
            . pack('v', 1)                            // 1 entrada
            . pack('vvVvv', 0x0112, 3, 1, 6, 0)       // Orientation SHORT = 6
            . pack('V', 0);                           // sin más IFDs
        $app1 = "Exif\0\0" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1;
        $jpeg = $this->gd($w, $h, 'jpeg');
        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2); // justo tras el SOI
    }

    public function testElResultadoNoLlevaExifYRespetaLaOrientacion(): void
    {
        $path = $this->file($this->jpegWithExif(80, 40));
        $this->assertSame(6, exif_read_data($path)['Orientation'] ?? null, 'el EXIF de prueba se lee');

        $out = $this->p->process($path, $this->p->inspect($path));

        $this->assertSame([40, 80], [$out['width'], $out['height']], 'girada antes de reencodar');
        foreach (['main', 'thumb'] as $k) {
            $this->assertStringStartsWith('RIFF', $out[$k]);
            $this->assertSame('WEBP', substr($out[$k], 8, 4));
            $this->assertStringNotContainsString('Exif', $out[$k]);
            $this->assertStringNotContainsString('EXIF', $out[$k]);
        }
    }

    public function testReduceA1600YMiniaturaA320(): void
    {
        $path = $this->file($this->gd(2000, 1000, 'png'));
        $out = $this->p->process($path, $this->p->inspect($path));
        $this->assertSame([1600, 800], [$out['width'], $out['height']]);
        $thumb = getimagesizefromstring($out['thumb']);
        $this->assertSame([320, 160], [$thumb[0], $thumb[1]]);
        $this->assertSame('image/webp', $thumb['mime']);
    }

    public function testNoAmpliaYAdmiteGifYWebp(): void
    {
        foreach (['gif', 'webp', 'jpeg'] as $format) {
            $path = $this->file($this->gd(120, 90, $format));
            $out = $this->p->process($path, $this->p->inspect($path));
            $this->assertSame([120, 90], [$out['width'], $out['height']], $format);
        }
    }

    public function testShaEsDelOriginal(): void
    {
        $bytes = $this->gd(10, 10, 'png');
        $this->assertSame(hash('sha256', $bytes), $this->p->sha256($this->file($bytes)));
    }
}
