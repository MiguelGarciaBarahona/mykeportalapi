<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finanzas (solo propietario): categorías de ingresos y gastos.
 * Incluye las categorías iniciales; se pueden renombrar o desactivar desde el panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                 // income | expense
            $table->string('name', 60);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['type', 'name']);
            $table->index(['type', 'is_active', 'sort']);
        });

        $now = now();
        $defaults = [
            'expense' => [
                'Alimentación', 'Transporte', 'Vivienda', 'Servicios (luz, agua, internet, teléfono)', 'Salud',
                'Educación', 'Entretenimiento', 'Software y herramientas', 'Equipo y tecnología',
                'Hosting y dominios', 'Impuestos y trámites', 'Otros gastos',
            ],
            'income' => ['Proyectos y servicios', 'Salario', 'Otros ingresos'],
        ];

        $rows = [];
        foreach ($defaults as $type => $names) {
            foreach ($names as $i => $name) {
                $rows[] = ['type' => $type, 'name' => $name, 'is_active' => true, 'sort' => $i, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('finance_categories')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_categories');
    }
};
