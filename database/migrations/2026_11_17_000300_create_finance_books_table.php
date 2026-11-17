<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Libros de finanzas (Personal, Empresa y los que cree el propietario).
 * Las categorías y los movimientos pasan a pertenecer a un libro.
 * Lo ya registrado queda en el libro «Personal». No se borra ningún dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_books', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('kind', 20);                 // personal | business | other
            $table->boolean('is_archived')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        $now = now();
        $personalId = DB::table('finance_books')->insertGetId(['name' => 'Personal', 'kind' => 'personal', 'sort' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $businessId = DB::table('finance_books')->insertGetId(['name' => 'Empresa', 'kind' => 'business', 'sort' => 2, 'created_at' => $now, 'updated_at' => $now]);

        // Categorías: pasan a ser propias de cada libro.
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->foreignId('book_id')->nullable()->after('id')->constrained('finance_books')->restrictOnDelete();
        });
        DB::table('finance_categories')->whereNull('book_id')->update(['book_id' => $personalId]);
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['type', 'name']);
            $table->unique(['book_id', 'type', 'name']);
        });

        // Movimientos existentes → libro Personal.
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('book_id')->nullable()->after('id')->constrained('finance_books')->restrictOnDelete();
            $table->index(['book_id', 'occurred_on']);
        });
        DB::table('finance_transactions')->whereNull('book_id')->update(['book_id' => $personalId]);

        // Categorías iniciales del libro Empresa.
        $business = [
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
        ];
        $rows = [];
        foreach ($business as $type => $names) {
            foreach ($names as $i => $name) {
                $rows[] = ['book_id' => $businessId, 'type' => $type, 'name' => $name, 'is_active' => true, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('finance_categories')->insert($rows);
    }

    public function down(): void
    {
        // Solo deshace la estructura si no hay datos que se perderían al quitar los libros.
        $personalId = DB::table('finance_books')->where('name', 'Personal')->value('id');
        $foreign = DB::table('finance_transactions')->whereNotNull('book_id')->where('book_id', '!=', $personalId)->exists();
        if ($foreign) {
            throw new RuntimeException('Hay movimientos en libros distintos de «Personal»: no se revierte para no perder información.');
        }

        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex(['book_id', 'occurred_on']);
            $table->dropConstrainedForeignId('book_id');
        });
        DB::table('finance_categories')->where('book_id', '!=', $personalId)->delete();
        Schema::table('finance_categories', function (Blueprint $table) {
            $table->dropUnique(['book_id', 'type', 'name']);
            $table->dropConstrainedForeignId('book_id');
            $table->unique(['type', 'name']);
        });
        Schema::dropIfExists('finance_books');
    }
};
