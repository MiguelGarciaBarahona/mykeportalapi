<?php

namespace App\Http\Requests\Admin\Finance;

use App\Enums\FinanceBookKind;
use App\Models\FinanceBook;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta (name + kind) o edición (name y/o is_archived) de un libro de finanzas. */
class FinanceBookRequest extends FormRequest
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
        /** @var FinanceBook|null $book */
        $book = $this->route('book');

        return [
            'name' => [$book ? 'sometimes' : 'required', 'string', 'min:2', 'max:60', Rule::unique('finance_books', 'name')->ignore($book?->id)],
            'kind' => $book ? ['prohibited'] : ['required', Rule::enum(FinanceBookKind::class)],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'name.min' => 'Escribe un nombre (mínimo 2 caracteres).',
            'name.max' => 'Máximo 60 caracteres.',
            'name.unique' => 'Ya tienes un libro con ese nombre.',
            'kind.enum' => 'Tipo de libro no válido.',
            'kind.prohibited' => 'El tipo de un libro no se puede cambiar.',
        ];
    }
}
