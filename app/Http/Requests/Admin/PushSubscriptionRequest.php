<?php

namespace App\Http\Requests\Admin;

use App\Push\PushNotifier;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/** Suscripción push del navegador actual (datos que entrega PushManager.subscribe()). */
class PushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $endpoint = ['required', 'string', 'max:1000', function (string $attribute, mixed $value, Closure $fail) {
            if (! is_string($value) || ! PushNotifier::isAllowedEndpoint($value)) {
                $fail('Este navegador usa un servicio de notificaciones no admitido.');
            }
        }];

        if ($this->routeIs('admin.push.status', 'admin.push.destroy', 'admin.push.test')) {
            return ['endpoint' => $this->routeIs('admin.push.test') ? ['nullable', 'string', 'max:1000'] : $endpoint];
        }

        return [
            'endpoint' => $endpoint,
            'keys.p256dh' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],
            'keys.auth' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_-]+$/'],
            'preferences.new_leads' => ['sometimes', 'boolean'],
            'preferences.stale_leads' => ['sometimes', 'boolean'],
            'preferences.month_end' => ['sometimes', 'boolean'],
            'device' => ['nullable', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Faltan datos de la suscripción del navegador.',
            'regex' => 'Datos de la suscripción no válidos.',
        ];
    }
}
