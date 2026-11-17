<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

/**
 * Formato uniforme de error del API del panel: {ok:false, code, message}.
 * Nunca incluye mensajes de excepción, clases, modelos ni identificadores internos.
 */
final class AdminErrorResponse
{
    private const ERRORS = [
        400 => ['bad_request', 'La petición no es válida.'],
        401 => ['unauthenticated', 'Inicia sesión para continuar.'],
        403 => ['forbidden', 'No tienes permiso para realizar esta acción.'],
        404 => ['not_found', 'El recurso solicitado no existe.'],
        405 => ['method_not_allowed', 'Método no permitido.'],
        409 => ['conflict', 'La operación no se puede realizar en el estado actual.'],
        413 => ['payload_too_large', 'La petición es demasiado grande.'],
        415 => ['unsupported_media_type', 'Formato de petición no admitido.'],
        419 => ['csrf_token_mismatch', 'La sesión de seguridad expiró. Recarga la página e inténtalo de nuevo.'],
        429 => ['too_many_requests', 'Recibimos demasiadas solicitudes. Inténtalo de nuevo más tarde.'],
        503 => ['service_unavailable', 'El servicio no está disponible en este momento.'],
    ];

    /**
     * @param  array<string, string|string[]>  $headers
     */
    public static function make(int $status, array $headers = [], ?string $code = null, ?string $message = null): JsonResponse
    {
        [$defaultCode, $defaultMessage] = self::ERRORS[$status]
            ?? ($status >= 500 ? ['server_error', 'Ocurrió un error inesperado.'] : ['error', 'No se pudo completar la petición.']);

        return response()->json([
            'ok' => false,
            'code' => $code ?? $defaultCode,
            'message' => $message ?? $defaultMessage,
        ], $status, $headers);
    }
}
