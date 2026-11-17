<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Respuesta idéntica para "enlace enviado" y "correo no encontrado / limitado":
 * así no se puede averiguar qué correos tienen cuenta en el panel.
 */
class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function __construct(protected string $status = '') {}

    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'message' => 'Si el correo pertenece a una cuenta activa, recibirás un enlace para restablecer la contraseña.',
        ]);
    }
}
