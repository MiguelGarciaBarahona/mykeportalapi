<?php

namespace App\Providers;

use App\Audit\AuthAuditSubscriber;
use App\Auth\ForgetResolvedGuards;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configurePasswords();
        $this->configureRateLimiting();

        // Finanzas personales: solo el propietario (cuenta activa).
        Gate::define('manage-finances', fn (User $user) => $user->is_active && $user->isOwner());

        // Auditoría de autenticación (inicios de sesión, 2FA, contraseñas).
        Event::subscribe(AuthAuditSubscriber::class);

        // Tras un logout no debe quedar ningún usuario memorizado en otros guards (sanctum).
        Event::listen(Logout::class, ForgetResolvedGuards::class);
    }

    /**
     * Política de contraseñas de los administradores.
     * (No se usa ->uncompromised() porque consulta un servicio externo; se puede
     * activar más adelante si se acepta esa dependencia.)
     */
    private function configurePasswords(): void
    {
        Password::defaults(fn () => Password::min(12)
            ->max(128)
            ->letters()
            ->mixedCase()
            ->numbers()
            ->symbols());
    }

    private function configureRateLimiting(): void
    {
        $tooMany = fn (Request $request, array $headers) => response()->json([
            'ok' => false,
            'code' => 'too_many_requests',
            'message' => 'Recibimos demasiadas solicitudes. Inténtalo de nuevo más tarde.',
            'retry_after' => (int) ($headers['Retry-After'] ?? 60),
        ], 429, $headers);

        /*
         * Formulario público: por IP (minuto y hora) + tope global diario.
         * La IP nunca se guarda en claro: la clave es un HMAC con APP_KEY.
         */
        RateLimiter::for('public-leads', function (Request $request) use ($tooMany) {
            $limits = config('mykesystems.rate_limits');
            $key = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

            return [
                Limit::perMinute((int) $limits['per_ip_per_minute'])->by('leads:m:'.$key)->response($tooMany),
                Limit::perHour((int) $limits['per_ip_per_hour'])->by('leads:h:'.$key)->response($tooMany),
                Limit::perDay((int) $limits['global_per_day'])->by('leads:global')->response($tooMany),
            ];
        });

        /*
         * API del panel: por administrador autenticado (o IP si no hay sesión).
         * Lecturas y escrituras tienen límites separados.
         */
        $adminKey = fn (Request $request) => $request->user()
            ? 'u:'.$request->user()->getAuthIdentifier()
            : 'ip:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        RateLimiter::for('admin-api', fn (Request $request) => Limit::perMinute(
            (int) config('mykesystems.admin_api.reads_per_minute', 120)
        )->by('admin-api:'.$adminKey($request))->response($tooMany));

        RateLimiter::for('admin-writes', fn (Request $request) => Limit::perMinute(
            (int) config('mykesystems.admin_api.writes_per_minute', 30)
        )->by('admin-writes:'.$adminKey($request))->response($tooMany));

        // Límite genérico por si algún grupo de rutas usa "throttle:api".
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by(
            hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))
        ));
    }
}
