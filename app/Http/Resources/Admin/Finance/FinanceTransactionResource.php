<?php

namespace App\Http\Resources\Admin\Finance;

use App\Models\FinanceTransaction;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimiento para el panel. Requiere cargar antes la relación `category`.
 * Nunca expone la ruta interna del comprobante.
 *
 * @mixin FinanceTransaction
 */
class FinanceTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var FinanceTransaction $t */
        $t = $this->resource;

        return [
            'id' => $t->id,
            'book_id' => $t->book_id,
            'type' => ['value' => $t->type->value, 'label' => $t->type->label()],
            'occurred_on' => $t->occurred_on->toDateString(),
            'amount_cents' => $t->amount_cents,
            'amount' => Money::format($t->amount_cents),
            'currency' => ['value' => $t->currency->value, 'label' => $t->currency->label(), 'symbol' => $t->currency->symbol()],
            'category' => ['id' => $t->category_id, 'name' => $t->category?->name],
            'description' => $t->description,
            'payment_method' => $t->payment_method ? ['value' => $t->payment_method->value, 'label' => $t->payment_method->label()] : null,
            'notes' => $t->notes,
            'receipt' => $t->hasReceipt()
                ? [
                    'name' => $t->receipt_name,
                    'mime' => $t->receipt_mime,
                    'size' => $t->receipt_size,
                    'url' => "/api/admin/finance/books/{$t->book_id}/transactions/{$t->id}/receipt",
                ]
                : null,
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
