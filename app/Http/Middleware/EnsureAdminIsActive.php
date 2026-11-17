<?php

namespace App\Http\Middleware;

use App\Audit\AuditLogger;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueo inmediato de cuentas desactivadas y duración máxima de la sesión.
 * Se revisa en CADA petición del panel: desactivar una cuenta la saca del panel
 * en su siguiente petición, aunque tuviera una sesión abierta.
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // El guard puede devolver una instancia de User ya cargada en memoria (sesión,
        // workers persistentes, pruebas). El estado de la cuenta se lee SIEMPRE de la
        // base de datos: una sola consulta por clave primaria, solo de este usuario.
        $current = $this->currentAccountState($user);

        if ($current === null || ! $current['is_active']) {
            AuditLogger::record('auth.blocked_inactive', $user);
            $this->endSession($request);

            return response()->json([
                'ok' => false,
                'code' => 'account_disabled',
                'message' => 'Tu cuenta está desactivada. Contacta al responsable del panel.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($request->hasSession()) {
            $session = $request->session();
            $loginAt = (int) $session->get('auth.login_at', 0);

            if ($loginAt === 0) {
                $session->put('auth.login_at', now()->getTimestamp());
            } elseif (now()->getTimestamp() - $loginAt > config('mykesystems.admin_session.absolute_lifetime_hours', 12) * 3600) {
                AuditLogger::record('auth.session_expired', $user);
                $this->endSession($request);

                return response()->json([
                    'ok' => false,
                    'code' => 'session_expired',
                    'message' => 'Tu sesión expiró. Vuelve a iniciar sesión.',
                ], Response::HTTP_UNAUTHORIZED);
            }
        }

        return $next($request);
    }

    /**
     * Estado vigente (activo y rol) de la cuenta autenticada, leído de la base de datos.
     * Sincroniza la instancia en memoria para que políticas y controladores posteriores
     * de esta petición usen los mismos valores. Devuelve null si la cuenta ya no existe.
     *
     * whereKey() es un método del builder de Eloquent: debe llamarse ANTES de cualquier
     * toBase(). Se consulta mediante el modelo para que los casts (is_active → bool,
     * role → AdminRole) se apliquen igual en SQLite y en MariaDB.
     *
     * @return array{is_active: bool, role: \App\Enums\AdminRole}|null
     */
    private function currentAccountState(User $user): ?array
    {
        $fresh = User::query()
            ->whereKey($user->getKey())
            ->first([$user->getKeyName(), 'is_active', 'role']);

        if ($fresh === null) {
            return null;
        }

        $user->forceFill(['is_active' => $fresh->is_active, 'role' => $fresh->role]);
        $user->syncOriginalAttributes(['is_active', 'role']);

        return ['is_active' => $fresh->is_active, 'role' => $fresh->role];
    }

    private function endSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
