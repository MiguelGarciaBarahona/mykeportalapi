<?php

namespace App\Models;

use App\Enums\FinanceBookKind;
use App\Support\FinanceTemplates;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Libro de finanzas (Personal, Empresa, …). Cada libro tiene sus categorías y movimientos.
 *
 * @property int $id
 * @property string $name
 * @property FinanceBookKind $kind
 * @property bool $is_archived
 * @property int $sort
 */
class FinanceBook extends Model
{
    /** @use HasFactory<\Database\Factories\FinanceBookFactory> */
    use HasFactory;

    protected $fillable = ['name', 'kind', 'is_archived', 'sort'];

    protected function casts(): array
    {
        return [
            'kind' => FinanceBookKind::class,
            'is_archived' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return HasMany<FinanceCategory, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(FinanceCategory::class, 'book_id');
    }

    /** @return HasMany<FinanceTransaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'book_id');
    }

    /** Crea las categorías iniciales según una plantilla. */
    public function seedCategories(FinanceBookKind $template): void
    {
        foreach (FinanceTemplates::for($template) as $type => $names) {
            foreach ($names as $i => $name) {
                $this->categories()->create(['type' => $type, 'name' => $name, 'sort' => $i + 1, 'is_active' => true]);
            }
        }
    }
}
