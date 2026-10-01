<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Model\ValueObjects\Email;
use PHPUnit\Framework\TestCase;

/**
 * `Email::toMasked()` es lo que loguea `MySqlUserRepository::findByEmail`: primera letra de la parte
 * local, `***` y el dominio entero.
 */
class EmailTest extends TestCase
{
    public function testEnmascaraLaParteLocalYConservaElDominio(): void
    {
        $this->assertSame('d***@gmail.com', Email::fromString('david@gmail.com')->toMasked());
    }

    public function testParteLocalDeUnaLetra(): void
    {
        $this->assertSame('a***@b.es', Email::fromString('a@b.es')->toMasked());
    }
}
