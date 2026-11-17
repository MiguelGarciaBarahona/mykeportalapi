<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El endpoint público solo acepta JSON y cuerpos pequeños.
 * (Nginx aplica además client_max_body_size.)
 */
class EnsureJsonRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('mykesystems.antispam.max_body_bytes', 10_240);
        $length = (int) $request->headers->get('Content-Length', '0');

        if ($length > $max || strlen((string) $request->getContent()) > $max) {
            return response()->json([
                'ok' => false,
                'code' => 'payload_too_large',
                'message' => 'La solicitud es demasiado grande.',
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        if (! $request->isJson()) {
            return response()->json([
                'ok' => false,
                'code' => 'unsupported_media_type',
                'message' => 'Formato no admitido.',
            ], Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        return $next($request);
    }
}
