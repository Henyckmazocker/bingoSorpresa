<?php

declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Ficheros de las fotos en disco: `storage/uploads/<user_id>/<sha256>.webp` (principal) y
 * `<sha256>_t.webp` (miniatura). En producción `storage/uploads` es el volumen
 * `bingo_uploads_prod`. Nunca se sirven por ruta pública: solo `public/img.php` con URL firmada.
 */
class UploadStorage
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? dirname(__DIR__, 3) . '/storage/uploads';
    }

    public function path(int $userId, string $sha256, bool $thumb = false): string
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('Invalid sha256');
        }
        return $this->root . '/' . $userId . '/' . $sha256 . ($thumb ? '_t' : '') . '.webp';
    }

    /**
     * Escribe principal y miniatura (cada una a un temporal y `rename`, para que img.php nunca lea
     * un fichero a medias). Devuelve los bytes en disco (= `uploads.bytes`).
     */
    public function write(int $userId, string $sha256, string $main, string $thumb): int
    {
        $dir = $this->root . '/' . $userId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create upload dir {$dir}");
        }
        foreach ([[false, $main], [true, $thumb]] as [$isThumb, $bytes]) {
            $target = $this->path($userId, $sha256, $isThumb);
            $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $bytes) !== strlen($bytes) || !@rename($tmp, $target)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot write {$target}");
            }
        }
        return strlen($main) + strlen($thumb);
    }

    /** Borra los dos ficheros; devuelve false si alguno no se pudo borrar (y existía). */
    public function remove(int $userId, string $sha256): bool
    {
        $ok = true;
        foreach ([false, true] as $isThumb) {
            $p = $this->path($userId, $sha256, $isThumb);
            if (is_file($p) && !@unlink($p)) {
                $ok = false;
            }
        }
        return $ok;
    }
}
