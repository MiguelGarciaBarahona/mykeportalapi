<?php

namespace App\Http\Resources\Admin;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Elemento del listado. Minimización: sin correo, teléfono ni mensaje (están en el
 * detalle, cuya consulta queda auditada). Sin identificadores internos.
 *
 * @mixin Lead
 */
class LeadListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        return [
            'id' => $lead->public_id,
            'full_name' => $lead->fullName(),
            'business' => $lead->business,
            'service' => ['value' => $lead->service, 'label' => $lead->serviceLabel()],
            'status' => ['value' => $lead->status->value, 'label' => $lead->status->label()],
            'notes_count' => (int) ($lead->notes_count ?? 0),
            'anonymized' => $lead->isAnonymized(),
            'created_at' => $lead->created_at?->toIso8601String(),
            'status_changed_at' => $lead->status_changed_at?->toIso8601String(),
        ];
    }
}
