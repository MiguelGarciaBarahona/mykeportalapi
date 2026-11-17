<?php

namespace App\Http\Resources\Admin;

use App\Models\LeadStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Entrada del historial de estados. changed_by = null cuando lo registró el sistema. @mixin LeadStatusChange */
class LeadStatusChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LeadStatusChange $change */
        $change = $this->resource;

        return [
            'from' => $change->from_status ? ['value' => $change->from_status->value, 'label' => $change->from_status->label()] : null,
            'to' => ['value' => $change->to_status->value, 'label' => $change->to_status->label()],
            'changed_by' => $change->user_id === null ? null : ['name' => $change->user?->name ?? 'Administrador eliminado'],
            'changed_at' => $change->created_at?->toIso8601String(),
        ];
    }
}
