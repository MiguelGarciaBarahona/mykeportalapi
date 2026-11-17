<?php

namespace App\Http\Resources\Admin\Finance;

use App\Models\FinanceCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FinanceCategory */
class FinanceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FinanceCategory $c */
        $c = $this->resource;

        return [
            'id' => $c->id,
            'type' => $c->type->value,
            'name' => $c->name,
            'is_active' => $c->is_active,
            'transactions_count' => $this->whenCounted('transactions'),
        ];
    }
}
