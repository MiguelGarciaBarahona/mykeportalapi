<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripciones push del panel (un registro por navegador/dispositivo de cada administrador)
 * y marca en solicitudes para avisar una sola vez de las que siguen sin atender.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();   // sha256 del endpoint (índice único corto)
            $table->text('endpoint');
            $table->string('public_key', 120);             // p256dh
            $table->string('auth_token', 60);              // auth
            $table->boolean('notify_new_leads')->default(true);
            $table->boolean('notify_stale_leads')->default(true);
            $table->boolean('notify_month_end')->default(false);
            $table->string('device', 80)->nullable();      // descripción corta (navegador · sistema)
            $table->timestamp('last_success_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('stale_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('stale_notified_at');
        });
        Schema::dropIfExists('push_subscriptions');
    }
};
