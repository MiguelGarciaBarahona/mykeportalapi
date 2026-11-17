<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo acepta solicitudes enviadas desde el portal (cabecera Origin).
 * Los navegadores siempre envían Origin en peticiones POST; si falta o no
 * coincide, la solicitud no proviene del formulario oficial.
 */
class VerifyPublicOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowed = config('mykesystems.allowed_origins', []);

        if (! is_string($origin) || ! in_array(rtrim($origin, '/'), $allowed, true)) {
            return response()->json([
                'ok' => false,
                'code' => 'forbidden_origin',
                'message' => 'Origen no permitido.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
