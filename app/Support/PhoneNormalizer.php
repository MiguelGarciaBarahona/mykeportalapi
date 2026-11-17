<?php

namespace App\Support;

/**
 * Normaliza números de teléfono a formato E.164 (+<código><número>).
 * Por defecto asume Honduras (+504) para números locales de 8 dígitos.
 * Sin dependencias de Laravel.
 */
final class PhoneNormalizer
{
    public const DEFAULT_COUNTRY_CODE = '504';

    /** Caracteres permitidos al escribir un número. */
    private const ALLOWED = '/^\+?[\d\s().\-]+$/';

    /**
     * Devuelve el número en E.164 o null si no es válido.
     */
    public static function normalize(?string $input, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        if ($input === null) {
            return null;
        }
        $raw = trim($input);
        if ($raw === '' || ! preg_match(self::ALLOWED, $raw)) {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw) ?? '';

        // Prefijo internacional "00" (p. ej. 00504...)
        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if (! $hasPlus) {
            if (strlen($digits) === 8) {
                // Número local hondureño: 9999-9999
                $digits = $countryCode.$digits;
            } elseif (! (str_starts_with($digits, $countryCode) && strlen($digits) === strlen($countryCode) + 8)) {
                // Sin "+" solo se aceptan números locales o con el código del país ya incluido.
                return null;
            }
        }

        // E.164: máximo 15 dígitos; mínimo razonable 8.
        if (strlen($digits) < 8 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
            return null;
        }

        // Honduras: código + exactamente 8 dígitos.
        if (str_starts_with($digits, '504') && strlen($digits) !== 11) {
            return null;
        }

        return '+'.$digits;
    }

    public static function isValid(?string $input): bool
    {
        return self::normalize($input) !== null;
    }
}
