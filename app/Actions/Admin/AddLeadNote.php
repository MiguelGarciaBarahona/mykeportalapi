<?php

namespace App\Actions\Admin;

use App\Audit\AuditLogger;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\User;

/** Agrega una nota interna. La auditoría registra el hecho, nunca el contenido. */
final class AddLeadNote
{
    public function handle(Lead $lead, string $body, User $user): LeadNote
    {
        /** @var LeadNote $note */
        $note = $lead->notes()->create([
            'user_id' => $user->getKey(),
            'body' => $body,
        ]);

        $lead->touch();
        AuditLogger::record('lead.note_added', $user, $lead);

        return $note->setRelation('author', $user);
    }
}
