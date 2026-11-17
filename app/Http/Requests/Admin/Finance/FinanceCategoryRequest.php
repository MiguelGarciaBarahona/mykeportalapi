<?php

namespace App\Http\Requests\Admin\Finance;

use App\Enums\FinanceType;
use App\Models\FinanceCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (type + name) o edición (name y/o is_active) de una categoría. */
class FinanceCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-finances');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($name = $this->input('name'))) {
            $this->merge(['name' => trim(preg_replace('/\s+/u', ' ', $name) ?? '')]);
        }
    }

    public function rules(): array
    {
        /** @var FinanceCategory|null $category */
        $category = $this->route('category');
        /** @var \App\Models\FinanceBook $book */
        $book = $this->route('book');
        $type = $category?->type->value ?? $this->input('type');

        return [
            'type' => $category ? ['prohibited'] : ['required', Rule::enum(FinanceType::class)],
            'name' => [
                $category ? 'sometimes' : 'required',
                'string',
                'min:2',
                'max:60',
                Rule::unique('finance_categories', 'name')->where('book_id', $book->id)->where('type', $type)->ignore($category?->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'name.min' => 'Escribe un nombre (mínimo 2 caracteres).',
            'name.max' => 'Máximo 60 caracteres.',
            'name.unique' => 'Ya existe una categoría con ese nombre en este libro.',
            'type.prohibited' => 'El tipo de una categoría no se puede cambiar.',
        ];
    }
}
