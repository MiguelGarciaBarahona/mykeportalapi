<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Models\User;

/** Permisos sobre las cuentas de administrador. */
class UserPolicy
{
    /** Ver la lista de administradores: solo owner. */
    public function viewAny(User $actor): bool
    {
        return $actor->isOwner();
    }

    /**
     * Desactivar: solo owner, nunca a sí mismo y nunca al último owner activo
     * (evita que el panel quede sin nadie que pueda administrarlo).
     */
    public function deactivate(User $actor, User $target): bool
    {
        if (! $actor->isOwner() || $actor->is($target)) {
            return false;
        }

        if ($target->role === AdminRole::Owner) {
            return User::where('role', AdminRole::Owner->value)->where('is_active', true)->count() > 1;
        }

        return true;
    }

    public function activate(User $actor, User $target): bool
    {
        return $actor->isOwner() && ! $actor->is($target);
    }
}
