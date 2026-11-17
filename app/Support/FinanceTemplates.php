<?php

namespace App\Support;

use App\Enums\FinanceBookKind;

/**
 * Categorías iniciales de cada libro según su tipo.
 * (Las migraciones tienen su propia copia para no depender de cambios futuros de esta clase.)
 */
final class FinanceTemplates
{
    /** @return array{expense: list<string>, income: list<string>} */
    public static function for(FinanceBookKind $kind): array
    {
        return match ($kind) {
            FinanceBookKind::Personal => [
                'expense' => [
                    'Alimentación', 'Transporte', 'Vivienda', 'Servicios (luz, agua, internet, teléfono)', 'Salud',
                    'Educación', 'Entretenimiento', 'Software y herramientas', 'Equipo y tecnología',
                    'Hosting y dominios', 'Impuestos y trámites', 'Otros gastos',
                ],
                'income' => ['Proyectos y servicios', 'Salario', 'Otros ingresos'],
            ],
            FinanceBookKind::Business => [
                'expense' => [
                    'Nómina y honorarios', 'Servidores y hosting', 'Dominios', 'Software y licencias',
                    'Equipo y tecnología', 'Publicidad y marketing', 'Contabilidad y servicios legales',
                    'Impuestos y trámites', 'Comisiones bancarias', 'Internet y telefonía',
                    'Oficina y alquiler', 'Transporte y viáticos', 'Otros gastos',
                ],
                'income' => [
                    'Desarrollo de software', 'Desarrollo web', 'Mantenimiento y soporte',
                    'Hosting a clientes', 'Consultoría', 'Otros ingresos',
                ],
            ],
            FinanceBookKind::Other => [
                'expense' => ['Gastos generales', 'Otros gastos'],
                'income' => ['Ingresos generales', 'Otros ingresos'],
            ],
        };
    }
}
