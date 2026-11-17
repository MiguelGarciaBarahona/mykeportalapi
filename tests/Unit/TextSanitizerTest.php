<?php

namespace Tests\Unit;

use App\Support\TextSanitizer;
use PHPUnit\Framework\TestCase;

class TextSanitizerTest extends TestCase
{
    public function test_line_recorta_y_colapsa_espacios(): void
    {
        $this->assertSame('Ana María', TextSanitizer::line("  Ana \t\n  María  "));
    }

    public function test_line_elimina_caracteres_de_control_e_invisibles(): void
    {
        $this->assertSame('Ana', TextSanitizer::line("A\u{0000}na\u{200B}\u{202E}"));
    }

    public function test_line_conserva_acentos_y_eñes(): void
    {
        $this->assertSame('Peña Núñez', TextSanitizer::line('Peña Núñez'));
    }

    public function test_line_normaliza_a_forma_canonica(): void
    {
        // "é" escrito como "e" + acento combinante se convierte en un solo carácter.
        $this->assertSame("Jos\u{00E9}", TextSanitizer::line("Jose\u{0301}"));
    }

    public function test_line_con_nulo_devuelve_nulo(): void
    {
        $this->assertNull(TextSanitizer::line(null));
    }

    public function test_multiline_conserva_parrafos_y_limita_lineas_en_blanco(): void
    {
        $input = "  Primera línea   con espacios.\r\n\r\n\r\n\r\nSegunda\tlínea.  \n";
        $this->assertSame("Primera línea con espacios.\n\nSegunda línea.", TextSanitizer::multiline($input));
    }

    public function test_multiline_elimina_bytes_invalidos(): void
    {
        $this->assertSame('Hola mundo', TextSanitizer::multiline("Hola \xFFmundo"));
    }
}
