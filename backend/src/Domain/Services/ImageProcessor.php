<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Exceptions\ImageRejectedException;

/**
 * Guardas y reencodado de las fotos que sube cualquiera (ver «El riesgo que manda» del plan).
 *
 * `inspect()` aplica las guardas SIN decodificar la imagen, en este orden:
 *   1. UPLOAD_ERR (subida rota o cortada por PHP)       → 400 / 413
 *   2. tamaño (> 10 MB)                                  → 413
 *   3. tipo real por `finfo` ∈ {jpeg, png, webp, gif}    → 415 (SVG incluido); y sin código PHP
 *      embebido (políglotas tipo «JPEG + <?php» al final) → 415
 *   4. `getimagesize()` lee la cabecera                  → 415 si no puede
 *   5. píxeles: w*h > 40 Mpx                             → 422. Va ANTES de `imagecreatefrom*`:
 *      un PNG de 20000×20000 es un fatal de memoria incapturable dentro de GD. La guarda es la de
 *      galleryVue (`MediaInspector::inspectImage`: `@getimagesize`) más el tope.
 *
 * `process()` decodifica (GIF: solo el primer fotograma), aplica la orientación EXIF y reencoda a
 * WebP: principal con lado mayor 1600 px y calidad 82, miniatura con lado mayor 320 px y calidad 75.
 * GD no escribe metadatos: el resultado no lleva EXIF, ni scripts, ni nada añadido tras la imagen.
 */
class ImageProcessor
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_PIXELS = 40_000_000;
    public const MAIN_SIDE = 1600;
    public const MAIN_QUALITY = 82;
    public const THUMB_SIDE = 320;
    public const THUMB_QUALITY = 75;

    /** MIME (según finfo) → IMAGETYPE_* que tiene que confirmar getimagesize. */
    private const ALLOWED = [
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/png' => IMAGETYPE_PNG,
        'image/webp' => IMAGETYPE_WEBP,
        'image/gif' => IMAGETYPE_GIF,
    ];

    /**
     * Guardas 1–5. No decodifica nada.
     *
     * @return array{mime:string,type:int,width:int,height:int}
     * @throws ImageRejectedException
     */
    public function inspect(string $path, int $uploadError = UPLOAD_ERR_OK): array
    {
        // 1. UPLOAD_ERR
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw match ($uploadError) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
                    => new ImageRejectedException('La foto pesa más de 10 MB.', 413),
                UPLOAD_ERR_NO_FILE => new ImageRejectedException('No ha llegado ninguna foto.', 400),
                UPLOAD_ERR_PARTIAL => new ImageRejectedException('La foto llegó cortada; vuelve a subirla.', 400),
                default => new ImageRejectedException('No se pudo recibir la foto.', 400),
            };
        }
        if (!is_file($path)) {
            throw new ImageRejectedException('No ha llegado ninguna foto.', 400);
        }

        // 2. Tamaño
        $size = filesize($path);
        if ($size === false || $size === 0) {
            throw new ImageRejectedException('La foto está vacía.', 400);
        }
        if ($size > self::MAX_BYTES) {
            throw new ImageRejectedException('La foto pesa más de 10 MB.', 413);
        }

        // 3. Tipo real (por contenido, nunca por extensión ni por el Content-Type del cliente)
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new ImageRejectedException('Solo se admiten fotos JPEG, PNG, WebP o GIF.', 415);
        }
        // Un políglota (imagen válida con código PHP pegado detrás o en un comentario) se rechaza
        // entero: el reencodado ya lo limpiaría, pero no hay foto legítima que lo lleve.
        $raw = file_get_contents($path);
        if ($raw === false || stripos($raw, '<?php') !== false) {
            throw new ImageRejectedException('Solo se admiten fotos JPEG, PNG, WebP o GIF.', 415);
        }
        unset($raw);

        // 4. Cabecera legible y coherente con finfo
        $info = @getimagesize($path);
        if ($info === false || ($info[2] ?? null) !== self::ALLOWED[$mime]) {
            throw new ImageRejectedException('El fichero no es una foto válida.', 415);
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1) {
            throw new ImageRejectedException('El fichero no es una foto válida.', 415);
        }

        // 5. Píxeles (antes de que GD reserve memoria)
        if ($width * $height > self::MAX_PIXELS) {
            throw new ImageRejectedException('La foto es demasiado grande (más de 40 megapíxeles).', 422);
        }

        return ['mime' => $mime, 'type' => self::ALLOWED[$mime], 'width' => $width, 'height' => $height];
    }

    /**
     * Decodifica, orienta y reencoda. Llamar solo con lo que devolvió `inspect()`.
     *
     * @param array{mime:string,type:int,width:int,height:int} $info
     * @return array{main:string,thumb:string,width:int,height:int}
     * @throws ImageRejectedException 422 si GD no puede decodificarla (truncada, corrupta)
     */
    public function process(string $path, array $info): array
    {
        $src = match ($info['type']) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path), // primer fotograma
            default => false,
        };
        if (!$src instanceof \GdImage) {
            throw new ImageRejectedException('La foto está dañada o incompleta.', 422);
        }
        if (!imageistruecolor($src)) {
            imagepalettetotruecolor($src);
        }

        if ($info['type'] === IMAGETYPE_JPEG) {
            $src = $this->applyExifOrientation($src, $path);
        }

        $main = $this->fit($src, self::MAIN_SIDE);
        imagedestroy($src);
        $width = imagesx($main);
        $height = imagesy($main);
        $mainBytes = $this->encode($main, self::MAIN_QUALITY);

        $thumb = $this->fit($main, self::THUMB_SIDE);
        imagedestroy($main);
        $thumbBytes = $this->encode($thumb, self::THUMB_QUALITY);
        imagedestroy($thumb);

        return ['main' => $mainBytes, 'thumb' => $thumbBytes, 'width' => $width, 'height' => $height];
    }

    /** sha256 del fichero ORIGINAL subido (la clave de la deduplicación). */
    public function sha256(string $path): string
    {
        return hash_file('sha256', $path);
    }

    /**
     * Gira/voltea según la etiqueta Orientation (1–8). Las cámaras de móvil guardan la foto «de
     * lado» y lo dicen en el EXIF; al reencodar se pierde el EXIF, así que hay que girarla antes.
     */
    private function applyExifOrientation(\GdImage $img, string $path): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        // imagerotate gira en sentido antihorario.
        [$angle, $flip] = match ($orientation) {
            2 => [0, IMG_FLIP_HORIZONTAL],
            3 => [180, null],
            4 => [0, IMG_FLIP_VERTICAL],
            5 => [270, IMG_FLIP_HORIZONTAL],
            6 => [270, null],
            7 => [90, IMG_FLIP_HORIZONTAL],
            8 => [90, null],
            default => [0, null],
        };
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated instanceof \GdImage) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
        if ($flip !== null) {
            imageflip($img, $flip);
        }
        return $img;
    }

    /** Copia nueva con el lado mayor ≤ $maxSide (nunca amplía), conservando la transparencia. */
    private function fit(\GdImage $src, int $maxSide): \GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, $maxSide / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $dst;
    }

    private function encode(\GdImage $img, int $quality): string
    {
        ob_start();
        $ok = imagewebp($img, null, $quality);
        $bytes = (string) ob_get_clean();
        if (!$ok || $bytes === '') {
            throw new \RuntimeException('WebP encoding failed');
        }
        return $bytes;
    }
}
