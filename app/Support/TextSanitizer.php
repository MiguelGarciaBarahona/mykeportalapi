<?php

namespace App\Support;

/**
 * Limpieza de texto recibido del formulario público.
 * Sin dependencias de Laravel (se prueba de forma aislada).
 */
final class TextSanitizer
{
    /** Una línea: nombres, teléfono, negocio, correo. */
    public static function line(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = self::normalize($value);
        // Todo espacio en blanco (incluidos saltos de línea y tabulaciones) → un espacio.
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /** Texto multilínea: descripción del proyecto. */
    public static function multiline(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = self::normalize($value);
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? '';      // espacios repetidos (no saltos)
        $value = preg_replace('/ *\n */u', "\n", $value) ?? '';        // espacios alrededor de saltos
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? '';      // máximo una línea en blanco

        return trim($value);
    }

    private static function normalize(string $value): string
    {
        // Elimina bytes inválidos en UTF-8 (sin reemplazarlos por "?").
        $previous = mb_substitute_character();
        mb_substitute_character('none');
        $value = mb_scrub($value, 'UTF-8');
        mb_substitute_character($previous);
        // Forma canónica Unicode (evita variantes visualmente idénticas).
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        }
        // Caracteres de control, de formato invisibles y separadores bidireccionales (excepto \n y \t).
        return preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';
    }
}
