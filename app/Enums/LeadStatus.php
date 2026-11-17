<?php

namespace App\Enums;

/** Estados de una solicitud en el panel administrativo. */
enum LeadStatus: string
{
    case Nueva = 'nueva';
    case Contactado = 'contactado';
    case EnNegociacion = 'en_negociacion';
    case Aceptada = 'aceptada';
    case Cerrada = 'cerrada';

    public function label(): string
    {
        return match ($this) {
            self::Nueva => 'Nueva',
            self::Contactado => 'Contactado',
            self::EnNegociacion => 'En negociación',
            self::Aceptada => 'Aceptada',
            self::Cerrada => 'Cerrada',
        };
    }

    /** Estados finales (se usan para la política de conservación). */
    public function isFinal(): bool
    {
        return $this === self::Aceptada || $this === self::Cerrada;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
