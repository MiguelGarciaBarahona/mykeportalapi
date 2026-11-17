<?php

namespace App\Http\Requests\Admin;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Queries\AdminLeadQuery;
use App\Support\TextSanitizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/admin/leads — parámetros del listado.
 *
 *  search     texto libre (nombre, correo, negocio, número MS-, teléfono)
 *  status     uno o varios estados, separados por coma o como arreglo; admite el grupo "en_proceso"
 *  service    uno o varios servicios (slug)
 *  date_from  fecha de recepción desde (Y-m-d, zona del panel)
 *  date_to    fecha de recepción hasta (Y-m-d, incluida)
 *  sort       -created_at (predeterminado) · created_at · -status_changed_at
 *  per_page   1..100 (predeterminado 20) · page ≥ 1
 */
class LeadIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Lead::class);
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (['status', 'service'] as $key) {
            if (is_string($value = $this->query($key))) {
                $data[$key] = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
            }
        }

        if (is_string($search = $this->query('search'))) {
            $data['search'] = TextSanitizer::line($search);
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        $dateTo = ['nullable', 'date_format:Y-m-d'];
        if ($this->filled('date_from')) {
            $dateTo[] = 'after_or_equal:date_from';
        }

        return [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'array', 'max:10'],
            'status.*' => ['string', Rule::in([...LeadStatus::values(), ...array_keys(AdminLeadQuery::STATUS_GROUPS)])],
            'service' => ['nullable', 'array', 'max:20'],
            'service.*' => ['string', Rule::in(array_keys((array) config('mykesystems.services')))],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => $dateTo,
            'sort' => ['nullable', Rule::in(array_keys(AdminLeadQuery::SORTS))],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('mykesystems.admin_api.per_page_max', 100)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.*.in' => 'El estado indicado no existe.',
            'service.*.in' => 'El servicio indicado no existe.',
            'date_from.date_format' => 'Usa el formato AAAA-MM-DD.',
            'date_to.date_format' => 'Usa el formato AAAA-MM-DD.',
            'date_to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
            'sort.in' => 'Orden no admitido.',
            'per_page.max' => 'Puedes pedir como máximo :max resultados por página.',
        ];
    }

    /** @return array{search:?string, status:list<string>, service:list<string>, date_from:?string, date_to:?string, sort:?string} */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'search' => $v['search'] ?? null,
            'status' => $v['status'] ?? [],
            'service' => $v['service'] ?? [],
            'date_from' => $v['date_from'] ?? null,
            'date_to' => $v['date_to'] ?? null,
            'sort' => $v['sort'] ?? null,
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated()['per_page'] ?? config('mykesystems.admin_api.per_page_default', 20));
    }
}
