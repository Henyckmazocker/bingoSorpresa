<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * Una subida que ImageProcessor no acepta. Lleva el código HTTP de la API:
 * 400 (subida rota) · 413 (> 10 MB) · 415 (tipo no admitido) · 422 (> 40 Mpx o no decodificable).
 */
class ImageRejectedException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $httpCode)
    {
        parent::__construct($message);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
