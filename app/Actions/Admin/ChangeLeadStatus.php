<?php

namespace App\Actions\Admin;

use App\Audit\AuditLogger;
use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambia el estado de una solicitud y registra el historial (quién, de qué estado
 * a cuál y cuándo). La fila se bloquea durante la transacción para que dos
 * administradores no registren cambios contradictorios al mismo tiempo.
 */
final class ChangeLeadStatus
{
    public function handle(Lead $lead, LeadStatus $to, User $user): LeadStatusChange
    {
        $change = DB::transaction(function () use ($lead, $to, $user): LeadStatusChange {
            /** @var Lead $locked */
            $locked = Lead::query()->whereKey($lead->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === $to) {
                throw ValidationException::withMessages([
                    'status' => 'La solicitud ya está en ese estado.',
                ]);
            }

            $from = $locked->status;
            $locked->forceFill(['status' => $to, 'status_changed_at' => now()])->save();

            return $locked->statusChanges()->create([
                'user_id' => $user->getKey(),
                'from_status' => $from,
                'to_status' => $to,
            ]);
        });

        $lead->refresh();
        AuditLogger::record('lead.status_changed', $user, $lead);

        return $change;
    }
}
