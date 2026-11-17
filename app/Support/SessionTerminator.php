<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cierra todas las sesiones abiertas de un usuario (driver "database").
 * Se usa al desactivar una cuenta o al restablecer la contraseña.
 * El middleware EnsureAdminIsActive bloquea además cualquier petición posterior.
 */
final class SessionTerminator
{
    public static function forUser(User $user): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
