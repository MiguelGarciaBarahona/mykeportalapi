<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Historial de estados: fecha, hora y responsable de cada cambio. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // null = sistema
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_status_changes');
    }
};
