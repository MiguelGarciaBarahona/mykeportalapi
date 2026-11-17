<?php

namespace App\Http\Requests\Admin\Finance;

use App\Enums\Currency;
use App\Enums\FinanceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros del listado de movimientos:
 *  month (AAAA-MM) · type · category_id · currency · search · page · per_page (1..100)
 */
class FinanceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-finances');
    }

    public function rules(): array
    {
        return [
            'month' => ['nullable', 'date_format:Y-m'],
            'type' => ['nullable', Rule::enum(FinanceType::class)],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', Rule::enum(Currency::class)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return ['month.date_format' => 'Usa el formato AAAA-MM.'];
    }
}
