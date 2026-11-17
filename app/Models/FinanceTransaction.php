<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\FinanceType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Movimiento de ingreso o gasto (finanzas del propietario).
 *
 * @property int $id
 * @property int $book_id
 * @property FinanceType $type
 * @property \Illuminate\Support\Carbon $occurred_on
 * @property int $amount_cents
 * @property Currency $currency
 * @property int $category_id
 * @property string $description
 * @property PaymentMethod|null $payment_method
 * @property string|null $notes
 * @property string|null $receipt_path
 * @property string|null $receipt_name
 * @property string|null $receipt_mime
 * @property int|null $receipt_size
 * @property int|null $created_by
 */
class FinanceTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\FinanceTransactionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'book_id',
        'type',
        'occurred_on',
        'amount_cents',
        'currency',
        'category_id',
        'description',
        'payment_method',
        'notes',
        'created_by',
    ];

    /** La ruta del archivo nunca sale en respuestas del API. */
    protected $hidden = ['receipt_path', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'type' => FinanceType::class,
            'occurred_on' => 'date',
            'amount_cents' => 'integer',
            'currency' => Currency::class,
            'payment_method' => PaymentMethod::class,
            'receipt_size' => 'integer',
        ];
    }

    public function hasReceipt(): bool
    {
        return is_string($this->receipt_path) && $this->receipt_path !== '';
    }

    /** @return BelongsTo<FinanceBook, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(FinanceBook::class, 'book_id');
    }

    /** @return BelongsTo<FinanceCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
