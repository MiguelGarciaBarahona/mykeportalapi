<?php

namespace App\Http\Resources\Admin;

use App\Models\LeadNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Nota interna: contenido, autor (solo nombre) y fechas. @mixin LeadNote */
class LeadNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LeadNote $note */
        $note = $this->resource;

        return [
            'body' => $note->body,
            'author' => $note->author ? ['name' => $note->author->name] : null,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
