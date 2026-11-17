<?php

namespace Tests\Unit;

use App\Support\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNormalizerTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validos(): array
    {
        return [
            'local con guion' => ['9999-0000', '+50499990000'],
            'local sin separadores' => ['99990000', '+50499990000'],
            'con +504 y espacios' => ['+504 9999 0000', '+50499990000'],
            'con 504 sin +' => ['504 9999-0000', '+50499990000'],
            'con 00504' => ['00504 9999 0000', '+50499990000'],
            'Estados Unidos' => ['+1 (415) 555-2671', '+14155552671'],
            'España' => ['+34 612 345 678', '+34612345678'],
        ];
    }

    #[DataProvider('validos')]
    public function test_normaliza_numeros_validos(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNormalizer::normalize($input));
    }

    /** @return array<string, array{?string}> */
    public static function invalidos(): array
    {
        return [
            'nulo' => [null],
            'vacío' => [''],
            'letras' => ['9999-abcd'],
            'muy corto' => ['12345'],
            'sin código y 10 dígitos (ambiguo)' => ['4155552671'],
            'Honduras con dígitos de más' => ['+504 9999 00001'],
            'Honduras con dígitos de menos' => ['+504 9999 000'],
            'más de 15 dígitos' => ['+1234567890123456'],
            'código que empieza en 0' => ['+0123456789'],
            'símbolos no permitidos' => ['9999*0000'],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_rechaza_numeros_invalidos(?string $input): void
    {
        $this->assertNull(PhoneNormalizer::normalize($input));
        $this->assertFalse(PhoneNormalizer::isValid($input));
    }
}
