<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes recibidas desde el formulario público.
 * Minimización: no se guarda la IP ni el agente de usuario del visitante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 16)->unique();          // MS-XXXXXX (visible para el cliente)

            // Datos de contacto
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('phone', 20);                        // normalizado E.164 (+504XXXXXXXX)
            $table->string('email', 254)->index();
            $table->string('business', 100)->nullable();

            // Solicitud
            $table->string('service', 40)->index();             // slug validado contra config('mykesystems.services')
            $table->text('message');
            $table->string('source', 60)->nullable();           // botón desde el que se abrió el formulario

            // Consentimiento de WhatsApp (independiente y opcional)
            $table->boolean('whatsapp_consent')->default(false);
            $table->timestamp('whatsapp_consent_at')->nullable();
            $table->string('consent_version', 30)->nullable();

            // Gestión interna
            $table->string('status', 20)->default('nueva');
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Conservación
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
