<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Enums\FinanceType;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceTransaction> */
class FinanceTransactionFactory extends Factory
{
    protected $model = FinanceTransaction::class;

    public function definition(): array
    {
        return [
            'type' => FinanceType::Expense,
            'occurred_on' => now()->toDateString(),
            'amount_cents' => $this->faker->numberBetween(100, 500_000),
            'currency' => Currency::HNL,
            'category_id' => FinanceCategory::factory(),
            // Siempre el libro de su categoría.
            'book_id' => fn (array $attributes) => FinanceCategory::query()->whereKey($attributes['category_id'])->value('book_id'),
            'description' => 'Movimiento de prueba',
            'payment_method' => null,
            'notes' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn () => [
            'type' => FinanceType::Income,
            'category_id' => FinanceCategory::factory()->income(),
        ]);
    }
}
