<?php

namespace App\Http\Requests\Admin\Finance;

use App\Enums\Currency;
use App\Enums\FinanceType;
use App\Enums\PaymentMethod;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edición de un movimiento (se envían todos los campos).
 * El monto llega como texto ("1234.56") y se guarda en centavos.
 */
class FinanceTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-finances');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'description' => is_string($d = $this->input('description')) ? trim(preg_replace('/\s+/u', ' ', $d) ?? '') : $d,
            'notes' => is_string($n = $this->input('notes')) ? (trim($n) === '' ? null : trim($n)) : $n,
            'payment_method' => $this->input('payment_method') === '' ? null : $this->input('payment_method'),
        ]);
    }

    public function rules(): array
    {
        /** @var FinanceTransaction|null $current */
        $current = $this->route('transaction');
        /** @var \App\Models\FinanceBook $book */
        $book = $this->route('book');

        return [
            'type' => ['required', Rule::enum(FinanceType::class)],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'amount' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (Money::toCents($value) === null) {
                        $fail('Escribe un monto mayor que cero con hasta 2 decimales (por ejemplo, 1250.50).');
                    }
                },
            ],
            'currency' => ['required', Rule::enum(Currency::class)],
            'category_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($current, $book) {
                    // Solo categorías del mismo libro.
                    $category = FinanceCategory::query()->where('book_id', $book->id)->find((int) $value);
                    if (! $category) {
                        $fail('Selecciona una categoría válida.');

                        return;
                    }
                    if ($category->type->value !== $this->input('type')) {
                        $fail('La categoría no corresponde al tipo de movimiento.');

                        return;
                    }
                    // Una categoría desactivada solo se admite si el movimiento ya la tenía.
                    if (! $category->is_active && $current?->category_id !== $category->id) {
                        $fail('La categoría está desactivada.');
                    }
                },
            ],
            'description' => ['required', 'string', 'min:2', 'max:160'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'occurred_on.date_format' => 'Usa el formato AAAA-MM-DD.',
            'occurred_on.after_or_equal' => 'La fecha es demasiado antigua.',
            'occurred_on.before_or_equal' => 'La fecha está demasiado en el futuro.',
            'description.min' => 'Escribe una descripción breve.',
            'description.max' => 'Máximo 160 caracteres.',
            'notes.max' => 'Máximo 1000 caracteres.',
            'type.enum' => 'Tipo de movimiento no válido.',
            'currency.enum' => 'Moneda no válida.',
            'payment_method.enum' => 'Forma de pago no válida.',
            'category_id.integer' => 'Selecciona una categoría válida.',
        ];
    }

    /** @return array{type:string,occurred_on:string,amount_cents:int,currency:string,category_id:int,description:string,payment_method:?string,notes:?string} */
    public function transactionData(): array
    {
        $v = $this->validated();

        return [
            'type' => $v['type'],
            'occurred_on' => $v['occurred_on'],
            'amount_cents' => (int) Money::toCents($v['amount']),
            'currency' => $v['currency'],
            'category_id' => (int) $v['category_id'],
            'description' => $v['description'],
            'payment_method' => $v['payment_method'] ?? null,
            'notes' => $v['notes'] ?? null,
        ];
    }
}
