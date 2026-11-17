<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

/**
 * Permisos sobre las solicitudes (se aplican en la Fase C).
 *  - owner y admin: ver, cambiar estado y agregar notas.
 *  - solo owner: exportar, eliminar y anonimizar.
 * Una cuenta inactiva no tiene ningún permiso.
 */
class LeadPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_active ? null : false;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Lead $lead): bool
    {
        return true;
    }

    public function update(User $user, Lead $lead): bool
    {
        return true;
    }

    public function addNote(User $user, Lead $lead): bool
    {
        return true;
    }

    public function export(User $user): bool
    {
        return $user->isOwner();
    }

    public function delete(User $user, Lead $lead): bool
    {
        return $user->isOwner();
    }

    public function anonymize(User $user, Lead $lead): bool
    {
        return $user->isOwner();
    }
}
