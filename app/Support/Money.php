<?php

namespace App\Support;

/**
 * Conversión exacta entre montos escritos ("1234.5", "1,234.56") y centavos enteros.
 * Nunca usa números de punto flotante.
 */
final class Money
{
    /** Máximo admitido por movimiento: 999,999,999.99 */
    public const MAX_CENTS = 99_999_999_999;

    /** Devuelve los centavos o null si el texto no es un monto válido (positivo, máximo 2 decimales). */
    public static function toCents(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $clean = str_replace([',', ' '], '', trim($value));
        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            return null;
        }

        $cents = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $cents > 0 && $cents <= self::MAX_CENTS ? $cents : null;
    }

    /** Centavos → "1234.56" (sin separador de miles, para el API y el CSV). */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
