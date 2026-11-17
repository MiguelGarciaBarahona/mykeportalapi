<?php

namespace App\Http\Requests\Admin;

use App\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/admin/leads/{lead}/notes — {body} */
class StoreLeadNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('addNote', $this->route('lead'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($body = $this->input('body'))) {
            $this->merge(['body' => TextSanitizer::multiline($body)]);
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('mykesystems.admin_api.note_max_length', 2000)],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Escribe el contenido de la nota.',
            'body.string' => 'La nota debe ser texto.',
            'body.max' => 'La nota no puede superar los :max caracteres.',
        ];
    }

    public function body(): string
    {
        return (string) $this->validated()['body'];
    }
}
