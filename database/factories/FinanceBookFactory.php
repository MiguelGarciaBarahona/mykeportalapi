<?php

namespace Database\Factories;

use App\Enums\FinanceBookKind;
use App\Models\FinanceBook;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinanceBook> */
class FinanceBookFactory extends Factory
{
    protected $model = FinanceBook::class;

    public function definition(): array
    {
        return [
            'name' => 'Libro '.$this->faker->unique()->numberBetween(1, 1_000_000),
            'kind' => FinanceBookKind::Other,
            'is_archived' => false,
            'sort' => 10,
        ];
    }
}
