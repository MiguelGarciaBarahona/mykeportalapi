<?php

namespace App\Audit;

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Traduce los eventos de autenticación a entradas de auditoría.
 * Ningún evento registra contraseñas, tokens, códigos ni el correo usado.
 */
class AuthAuditSubscriber
{
    private static function user(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, function (Login $e) {
            // Inicio del conteo de la duración máxima de la sesión.
            if (request()->hasSession()) {
                request()->session()->put('auth.login_at', now()->getTimestamp());
            }
            if ($user = self::user($e->user)) {
                $user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
            AuditLogger::record('auth.login', self::user($e->user));
        });

        $events->listen(Failed::class, fn (Failed $e) => AuditLogger::record('auth.login_failed', self::user($e->user)));
        $events->listen(Lockout::class, fn () => AuditLogger::record('auth.lockout'));
        $events->listen(Logout::class, fn (Logout $e) => AuditLogger::record('auth.logout', self::user($e->user)));
        $events->listen(PasswordReset::class, fn (PasswordReset $e) => AuditLogger::record('auth.password_reset', self::user($e->user)));
        $events->listen(PasswordUpdatedViaController::class, fn ($e) => AuditLogger::record('auth.password_updated', self::user($e->user ?? null)));

        $events->listen(TwoFactorAuthenticationEnabled::class, fn ($e) => AuditLogger::record('2fa.enabled', self::user($e->user ?? null)));
        $events->listen(TwoFactorAuthenticationConfirmed::class, fn ($e) => AuditLogger::record('2fa.confirmed', self::user($e->user ?? null)));
        $events->listen(TwoFactorAuthenticationDisabled::class, fn ($e) => AuditLogger::record('2fa.disabled', self::user($e->user ?? null)));
        $events->listen(TwoFactorAuthenticationChallenged::class, fn ($e) => AuditLogger::record('2fa.challenged', self::user($e->user ?? null)));
        $events->listen(ValidTwoFactorAuthenticationCodeProvided::class, fn ($e) => AuditLogger::record('2fa.passed', self::user($e->user ?? null)));
        $events->listen(TwoFactorAuthenticationFailed::class, fn ($e) => AuditLogger::record('2fa.failed', self::user($e->user ?? null)));
        $events->listen(RecoveryCodesGenerated::class, fn ($e) => AuditLogger::record('2fa.recovery_codes_generated', self::user($e->user ?? null)));
        $events->listen(RecoveryCodeReplaced::class, fn ($e) => AuditLogger::record('2fa.recovery_code_used', self::user($e->user ?? null)));
    }
}
