<?php

namespace App\Support;

/**
 * Genera el número de solicitud visible para el cliente: MS-XXXXXX.
 * Aleatorio (no secuencial) para no revelar cuántas solicitudes recibe la empresa.
 * Alfabeto sin caracteres ambiguos (0/O, 1/I/L). Sin dependencias de Laravel.
 */
final class PublicIdGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    private const LENGTH = 6;
    public const PATTERN = '/^MS-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/';

    /**
     * @param  callable(string): bool  $exists  devuelve true si el código ya está en uso
     */
    public static function generate(callable $exists, int $maxAttempts = 10): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = 'MS-';
            for ($j = 0; $j < self::LENGTH; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (! $exists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('No se pudo generar un número de solicitud único.');
    }
}
