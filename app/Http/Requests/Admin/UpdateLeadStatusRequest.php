<?php

namespace App\Http\Requests\Admin;

use App\Enums\LeadStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /api/admin/leads/{lead}/status — {status} */
class UpdateLeadStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('lead'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::enum(LeadStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Indica el nuevo estado.',
            'status.*' => 'El estado indicado no existe.',
        ];
    }

    public function status(): LeadStatus
    {
        return LeadStatus::from($this->validated()['status']);
    }
}
