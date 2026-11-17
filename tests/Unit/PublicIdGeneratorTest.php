<?php

namespace Tests\Unit;

use App\Support\PublicIdGenerator;
use PHPUnit\Framework\TestCase;

class PublicIdGeneratorTest extends TestCase
{
    public function test_genera_el_formato_esperado(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->assertMatchesRegularExpression(PublicIdGenerator::PATTERN, PublicIdGenerator::generate(fn () => false));
        }
    }

    public function test_reintenta_si_el_codigo_ya_existe(): void
    {
        $calls = 0;
        $id = PublicIdGenerator::generate(function () use (&$calls) {
            return ++$calls < 3; // los dos primeros "ya existen"
        });

        $this->assertSame(3, $calls);
        $this->assertMatchesRegularExpression(PublicIdGenerator::PATTERN, $id);
    }

    public function test_falla_de_forma_controlada_si_no_encuentra_codigo_libre(): void
    {
        $this->expectException(\RuntimeException::class);
        PublicIdGenerator::generate(fn () => true, maxAttempts: 3);
    }
}
