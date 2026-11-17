<?php

namespace App\Enums;

/** Monedas admitidas. Los totales de cada moneda se calculan por separado (sin conversión). */
enum Currency: string
{
    case HNL = 'HNL';
    case USD = 'USD';

    public function label(): string
    {
        return match ($this) {
            self::HNL => 'Lempiras',
            self::USD => 'Dólares',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::HNL => 'L',
            self::USD => '$',
        };
    }
}
