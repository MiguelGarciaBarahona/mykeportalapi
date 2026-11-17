<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;

/**
 * Configuración de Laravel Fortify para el panel administrativo.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Las rutas se declaran en routes/admin-auth.php (sin registro público,
        // sin desactivación del 2FA y limitadas al dominio del panel).
        Fortify::ignoreRoutes();

        // Misma respuesta exista o no el correo: evita revelar qué cuentas existen.
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        /*
         * Inicio de sesión: solo administradores activos.
         * Cualquier fallo (correo inexistente, contraseña incorrecta o cuenta
         * desactivada) produce el mismo error genérico de Fortify.
         */
        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower((string) $request->input(Fortify::username()));
            $user = User::where('email', $email)->first();

            if (! $user) {
                // Tiempo similar al de una cuenta real: evita distinguir correos por tiempo de respuesta.
                Hash::check((string) $request->input('password'), self::dummyHash());

                return null;
            }

            if (! Hash::check((string) $request->input('password'), $user->password) || ! $user->is_active) {
                return null;
            }

            return $user;
        });

        // Enlace de restablecimiento: apunta al panel (SPA), nunca a una vista de Laravel.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return config('mykesystems.notifications.admin_panel_url')
                .'/restablecer-contrasena?token='.urlencode($token)
                .'&email='.urlencode($user->email);
        });

        $this->configureRateLimiting();
    }

    /** Hash ficticio (creado una vez por proceso) con el algoritmo configurado. */
    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(40));
    }

    private function configureRateLimiting(): void
    {
        $ipKey = fn (Request $request): string => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $emailKey = fn (Request $request): string => hash_hmac(
            'sha256',
            Str::lower((string) $request->input(Fortify::username())),
            (string) config('app.key')
        );

        // Inicio de sesión: por correo+IP, por IP (relleno de credenciales) y por cuenta (fuerza bruta lenta).
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:pair:'.$emailKey($request).'|'.$ipKey($request)),
            Limit::perMinute(20)->by('login:ip:'.$ipKey($request)),
            Limit::perHour(30)->by('login:account:'.$emailKey($request)),
        ]);

        // Código 2FA: por intento de inicio de sesión pendiente y por IP.
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('2fa:'.$request->session()->get('login.id')),
            Limit::perMinute(10)->by('2fa:ip:'.$ipKey($request)),
        ]);

        // Recuperación de contraseña.
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('reset:ip:'.$ipKey($request)),
            Limit::perHour(5)->by('reset:account:'.$emailKey($request)),
        ]);

        // Confirmación de contraseña y configuración del 2FA (usuario autenticado).
        RateLimiter::for('password-confirm', fn (Request $request) => Limit::perMinute(5)
            ->by('confirm:'.($request->user()?->getAuthIdentifier() ?? $ipKey($request))));

        RateLimiter::for('two-factor-setup', fn (Request $request) => Limit::perMinute(5)
            ->by('2fa-setup:'.($request->user()?->getAuthIdentifier() ?? $ipKey($request))));
    }
}
