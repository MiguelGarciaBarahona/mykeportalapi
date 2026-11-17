<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segundo factor obligatorio: mientras el administrador no haya configurado y
 * confirmado su TOTP, solo puede usar /me, cerrar sesión y las rutas de
 * configuración del 2FA. Todo lo demás responde 403.
 */
class EnsureTwoFactorConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasConfirmedTwoFactor()) {
            return response()->json([
                'ok' => false,
                'code' => 'two_factor_setup_required',
                'message' => 'Configura la verificación en dos pasos para continuar.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
