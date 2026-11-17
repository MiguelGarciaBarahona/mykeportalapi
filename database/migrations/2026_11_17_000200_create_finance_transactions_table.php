<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finanzas (solo propietario): movimientos de ingresos y gastos.
 * - El monto se guarda en centavos (entero) para que las sumas sean exactas.
 * - El comprobante (foto o PDF) vive en un disco privado; aquí solo la ruta y sus metadatos.
 * - Borrado lógico: un movimiento eliminado se puede recuperar desde la base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);                          // income | expense
            $table->date('occurred_on');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);                         // HNL | USD
            $table->foreignId('category_id')->constrained('finance_categories')->restrictOnDelete();
            $table->string('description', 160);
            $table->string('payment_method', 20)->nullable();    // cash | card | transfer | other
            $table->text('notes')->nullable();

            $table->string('receipt_path', 255)->nullable();
            $table->string('receipt_name', 180)->nullable();
            $table->string('receipt_mime', 60)->nullable();
            $table->unsignedInteger('receipt_size')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['occurred_on', 'id']);
            $table->index(['type', 'occurred_on']);
            $table->index(['currency', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_transactions');
    }
};
