<?php

namespace App\Models;

use App\Enums\FinanceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de ingresos o gastos (finanzas del propietario).
 *
 * @property int $id
 * @property int $book_id
 * @property FinanceType $type
 * @property string $name
 * @property bool $is_active
 * @property int $sort
 */
class FinanceCategory extends Model
{
    /** @use HasFactory<\Database\Factories\FinanceCategoryFactory> */
    use HasFactory;

    protected $fillable = ['book_id', 'type', 'name', 'is_active', 'sort'];

    protected function casts(): array
    {
        return [
            'type' => FinanceType::class,
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<FinanceBook, $this> */
    public function book(): BelongsTo
    {
        return $this->belongsTo(FinanceBook::class, 'book_id');
    }

    /** @return HasMany<FinanceTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'category_id');
    }
}
