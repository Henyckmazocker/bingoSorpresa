<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/** La subida no cabe en la cuota del usuario (la API responde 507). */
class QuotaExceededException extends \RuntimeException
{
}
