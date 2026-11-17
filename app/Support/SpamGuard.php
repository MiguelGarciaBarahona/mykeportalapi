<?php

namespace App\Support;

/**
 * Heurísticas antispam del formulario público. Sin dependencias de Laravel.
 *
 * - Campo trampa (honeypot): una persona nunca lo completa porque está oculto.
 * - Tiempo de llenado: un envío en menos de N segundos es automático.
 * - Antigüedad del formulario: descarta envíos con marcas de tiempo absurdas.
 * - Enlaces: el spam suele incluir varios enlaces en la descripción.
 */
final class SpamGuard
{
    public const HONEYPOT = 'honeypot';
    public const TOO_FAST = 'too_fast';
    public const STALE_OR_INVALID_TIME = 'invalid_time';
    public const TOO_MANY_LINKS = 'too_many_links';

    public function __construct(
        private readonly int $minFillSeconds = 3,
        private readonly int $maxFormAgeHours = 24,
        private readonly int $maxLinks = 2,
    ) {}

    /**
     * @param  string|null  $honeypot  valor del campo trampa
     * @param  int|null  $startedAtMs  momento en que se abrió el formulario (ms desde epoch, reloj del navegador)
     * @param  string  $message  descripción del proyecto
     * @param  int  $nowMs  hora actual del servidor (ms)
     * @return string|null motivo del rechazo, o null si pasa
     */
    public function check(?string $honeypot, ?int $startedAtMs, string $message, int $nowMs): ?string
    {
        if ($honeypot !== null && trim($honeypot) !== '') {
            return self::HONEYPOT;
        }

        if ($startedAtMs === null || $startedAtMs <= 0) {
            return self::STALE_OR_INVALID_TIME;
        }
        $elapsedMs = $nowMs - $startedAtMs;
        // Tolerancia de 5 minutos por diferencias de reloj entre el navegador y el servidor.
        if ($elapsedMs < -300_000 || $elapsedMs > $this->maxFormAgeHours * 3_600_000) {
            return self::STALE_OR_INVALID_TIME;
        }
        if ($elapsedMs >= 0 && $elapsedMs < $this->minFillSeconds * 1000) {
            return self::TOO_FAST;
        }

        if (self::countLinks($message) > $this->maxLinks) {
            return self::TOO_MANY_LINKS;
        }

        return null;
    }

    public static function countLinks(string $text): int
    {
        return preg_match_all('~(https?://|www\.)~iu', $text);
    }
}
