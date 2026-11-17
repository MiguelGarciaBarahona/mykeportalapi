<?php

namespace App\Http\Requests;

use App\Support\PhoneNormalizer;
use App\Support\TextSanitizer;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del lado del servidor de una solicitud pública.
 * Nunca se confía en la validación del navegador.
 *
 * Contrato (JSON, snake_case):
 *   first_name, last_name, phone, email, business?, service, message,
 *   source?, started_at (ms), website (campo trampa)
 *
 * MykeSystems no usa WhatsApp para gestionar solicitudes. Por compatibilidad con
 * versiones anteriores del portal, whatsapp_consent y consent_version se aceptan
 * pero se descartan (regla «exclude»): nunca se validan ni se guardan.
 */
class StoreLeadRequest extends FormRequest
{
    /** Endpoint público: no requiere sesión. */
    public function authorize(): bool
    {
        return true;
    }

    /** Limpieza previa: caracteres de control, espacios y formato. */
    protected function prepareForValidation(): void
    {
        $str = fn (string $key): ?string => is_string($this->input($key)) ? $this->input($key) : null;

        $this->merge([
            'first_name' => TextSanitizer::line($str('first_name')),
            'last_name' => TextSanitizer::line($str('last_name')),
            'phone' => TextSanitizer::line($str('phone')),
            'email' => ($email = TextSanitizer::line($str('email'))) !== null ? mb_strtolower($email) : null,
            'business' => TextSanitizer::line($str('business')) ?: null,
            'message' => TextSanitizer::multiline($str('message')),
            'source' => TextSanitizer::line($str('source')) ?: null,
        ]);
    }

    public function rules(): array
    {
        $name = ['required', 'string', 'min:2', 'max:60', "regex:/^[\\pL\\pM][\\pL\\pM' .\\-]*$/u"];

        return [
            'first_name' => $name,
            'last_name' => $name,
            'phone' => [
                'required', 'string', 'max:25',
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! PhoneNormalizer::isValid(is_string($value) ? $value : null)) {
                        $fail('Escribe un número válido. Si no es de Honduras, incluye el código de país (por ejemplo, +1).');
                    }
                },
            ],
            'email' => ['required', 'string', 'max:254', 'email:rfc,strict'],
            'business' => ['nullable', 'string', 'max:100'],
            'service' => ['required', 'string', Rule::in(array_keys(config('mykesystems.services')))],
            'message' => ['required', 'string', 'min:20', 'max:2000'],
            // Campos retirados: se ignoran si un cliente antiguo los envía.
            'whatsapp_consent' => ['exclude'],
            'consent_version' => ['exclude'],
            'source' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9:_\-]+$/'],
            'started_at' => ['required', 'integer'],
            config('mykesystems.antispam.honeypot_field') => ['nullable', 'string', 'max:200'],
        ];
    }

    public function messages(): array
    {
        return [
            'required' => 'Este campo es obligatorio.',
            'first_name.min' => 'Escribe tu nombre.',
            'last_name.min' => 'Escribe tu apellido.',
            'first_name.regex' => 'El nombre solo puede contener letras, espacios, apóstrofos y guiones.',
            'last_name.regex' => 'El apellido solo puede contener letras, espacios, apóstrofos y guiones.',
            'max' => 'El texto es demasiado largo.',
            'email.email' => 'Escribe un correo válido, por ejemplo: nombre@dominio.com.',
            'service.in' => 'Selecciona un servicio válido.',
            'message.min' => 'Cuéntanos un poco más (mínimo 20 caracteres).',
            'message.max' => 'La descripción no puede superar los 2000 caracteres.',
            'source.regex' => 'Origen no válido.',
            'started_at.integer' => 'Solicitud no válida.',
        ];
    }

    /**
     * Datos listos para guardar (teléfono en E.164).
     *
     * @return array{first_name:string,last_name:string,phone:string,email:string,business:?string,service:string,message:string,source:?string}
     */
    public function leadData(): array
    {
        $v = $this->validated();

        return [
            'first_name' => $v['first_name'],
            'last_name' => $v['last_name'],
            'phone' => (string) PhoneNormalizer::normalize($v['phone']),
            'email' => $v['email'],
            'business' => $v['business'] ?? null,
            'service' => $v['service'],
            'message' => $v['message'],
            'source' => $v['source'] ?? null,
        ];
    }
}
