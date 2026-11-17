<?php

namespace Database\Factories;

use App\Enums\FinanceType;
use App\Models\FinanceBook;
use App\Models\FinanceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceCategory> */
class FinanceCategoryFactory extends Factory
{
    protected $model = FinanceCategory::class;

    public function definition(): array
    {
        return [
            // Por defecto, el libro «Personal» que crea la migración.
            'book_id' => fn () => FinanceBook::query()->where('kind', 'personal')->orderBy('id')->value('id') ?? FinanceBook::factory(),
            'type' => FinanceType::Expense,
            'name' => 'Categoría '.$this->faker->unique()->numberBetween(1, 1_000_000),
            'is_active' => true,
            'sort' => 0,
        ];
    }

    public function income(): static
    {
        return $this->state(['type' => FinanceType::Income]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
