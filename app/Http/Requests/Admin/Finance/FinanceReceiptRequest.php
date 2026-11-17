<?php

namespace App\Http\Requests\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;

/** Comprobante de un movimiento: foto (JPG, PNG, WEBP) o PDF de hasta 5 MB. */
class FinanceReceiptRequest extends FormRequest
{
    public const MAX_KB = 5120;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage-finances');
    }

    public function rules(): array
    {
        return [
            'receipt' => [
                'required',
                'file',
                'max:'.self::MAX_KB,
                'mimes:jpg,jpeg,png,webp,pdf',
                'mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'receipt.required' => 'Selecciona un archivo.',
            'receipt.file' => 'No se pudo recibir el archivo. Si es muy grande, prueba con uno de hasta 5 MB.',
            'receipt.uploaded' => 'No se pudo recibir el archivo. Si es muy grande, prueba con uno de hasta 5 MB.',
            'receipt.max' => 'El archivo no puede superar los 5 MB.',
            'receipt.mimes' => 'Sube una foto (JPG, PNG o WEBP) o un PDF.',
            'receipt.mimetypes' => 'Sube una foto (JPG, PNG o WEBP) o un PDF.',
        ];
    }
}
