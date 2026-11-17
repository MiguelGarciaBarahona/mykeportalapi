<?php

namespace App\Enums;

/** Tipo de libro de finanzas: define las categorías iniciales y el ícono en el panel. */
enum FinanceBookKind: string
{
    case Personal = 'personal';
    case Business = 'business';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::Business => 'Empresa',
            self::Other => 'Otro',
        };
    }
}
