<?php

namespace App\Http\Resources\Admin;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Detalle completo de una solicitud. Requiere cargar antes las relaciones
 * statusChanges.user y notes.author (LeadController::loadDetail).
 *
 * @mixin Lead
 */
class LeadDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;
        $user = $request->user();

        return [
            'id' => $lead->public_id,
            'status' => ['value' => $lead->status->value, 'label' => $lead->status->label()],
            'status_changed_at' => $lead->status_changed_at?->toIso8601String(),
            'service' => ['value' => $lead->service, 'label' => $lead->serviceLabel()],
            'contact' => [
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'full_name' => $lead->fullName(),
                'email' => $lead->email,
                'phone' => $lead->phone,
                'business' => $lead->business,
            ],
            'message' => $lead->message,
            'source' => $lead->source,
            'anonymized' => $lead->isAnonymized(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'updated_at' => $lead->updated_at?->toIso8601String(),
            'history' => LeadStatusChangeResource::collection($lead->statusChanges)->resolve($request),
            'notes' => LeadNoteResource::collection($lead->notes)->resolve($request),
            'permissions' => [
                'update_status' => ! $lead->isAnonymized() && (bool) $user?->can('update', $lead),
                'add_note' => ! $lead->isAnonymized() && (bool) $user?->can('addNote', $lead),
            ],
        ];
    }
}
