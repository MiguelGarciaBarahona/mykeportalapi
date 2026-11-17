<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Support\SessionTerminator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Desactiva una cuenta de administrador de inmediato y cierra sus sesiones.
 * Útil ante una emergencia (cuenta comprometida) aunque nadie pueda entrar al panel.
 */
class DeactivateAdmin extends Command
{
    protected $signature = 'admin:deactivate {email : Correo del administrador}';

    protected $description = 'Desactiva un administrador y cierra todas sus sesiones';

    public function handle(): int
    {
        $user = User::where('email', Str::lower((string) $this->argument('email')))->first();

        if (! $user) {
            $this->error('No existe un administrador con ese correo.');

            return self::FAILURE;
        }

        $user->forceFill(['is_active' => false])->setRememberToken(null);
        $user->save();
        $closed = SessionTerminator::forUser($user);

        AuditLogger::record('admin.deactivated', null, $user, (string) $user->id);

        $this->info("Administrador desactivado. Sesiones cerradas: {$closed}.");

        return self::SUCCESS;
    }
}
