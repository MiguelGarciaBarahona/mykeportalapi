<?php

/*
|--------------------------------------------------------------------------
| Autenticación del panel (Laravel Fortify) — admin.mykesystems.com/api/admin/auth
|--------------------------------------------------------------------------
| Se registran a mano en lugar de usar las rutas automáticas de Fortify para:
|  - NO exponer registro público de usuarios,
|  - NO permitir desactivar el segundo factor (es obligatorio),
|  - limitar todo al dominio del panel y aplicar límites de intentos propios.
| Middleware del grupo: "web" (sesión + cookies cifradas + protección CSRF).
*/

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\ConfirmedPasswordStatusController;
use Laravel\Fortify\Http\Controllers\ConfirmedTwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticationController;
use Laravel\Fortify\Http\Controllers\TwoFactorQrCodeController;
use Laravel\Fortify\Http\Controllers\TwoFactorSecretKeyController;

$guard = config('fortify.guard', 'web');
$guest = 'guest:'.$guard;
$auth = [config('fortify.auth_middleware', 'auth').':'.$guard, 'admin.active'];
$twoFactorManagement = [...$auth, 'password.confirm'];

// ── Inicio y cierre de sesión ───────────────────────────────────────────
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware([$guest, 'throttle:'.config('fortify.limiters.login', 'login')])
    ->name('login');

Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])
    ->middleware([$guest, 'throttle:'.config('fortify.limiters.two-factor', 'two-factor')])
    ->name('two-factor.login.store');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(config('fortify.auth_middleware', 'auth').':'.$guard)
    ->name('logout');

// ── Recuperación de contraseña ──────────────────────────────────────────
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware([$guest, 'throttle:password-reset'])
    ->name('password.email');

Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware([$guest, 'throttle:password-reset'])
    ->name('password.update');

// ── Contraseña del administrador autenticado ────────────────────────────
Route::put('/user/password', [PasswordController::class, 'update'])
    ->middleware($auth)
    ->name('user-password.update');

Route::get('/user/confirmed-password-status', [ConfirmedPasswordStatusController::class, 'show'])
    ->middleware($auth)
    ->name('password.confirmation');

Route::post('/user/confirm-password', [ConfirmablePasswordController::class, 'store'])
    ->middleware([...$auth, 'throttle:password-confirm'])
    ->name('password.confirm');

// ── Configuración del segundo factor (TOTP) ─────────────────────────────
// Requiere sesión iniciada y contraseña confirmada recientemente.
Route::post('/user/two-factor-authentication', [TwoFactorAuthenticationController::class, 'store'])
    ->middleware($twoFactorManagement)
    ->name('two-factor.enable');

Route::post('/user/confirmed-two-factor-authentication', [ConfirmedTwoFactorAuthenticationController::class, 'store'])
    ->middleware([...$twoFactorManagement, 'throttle:two-factor-setup'])
    ->name('two-factor.confirm');

Route::get('/user/two-factor-qr-code', [TwoFactorQrCodeController::class, 'show'])
    ->middleware($twoFactorManagement)
    ->name('two-factor.qr-code');

Route::get('/user/two-factor-secret-key', [TwoFactorSecretKeyController::class, 'show'])
    ->middleware($twoFactorManagement)
    ->name('two-factor.secret-key');

Route::get('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'index'])
    ->middleware($twoFactorManagement)
    ->name('two-factor.recovery-codes');

Route::post('/user/two-factor-recovery-codes', [RecoveryCodeController::class, 'store'])
    ->middleware($twoFactorManagement)
    ->name('two-factor.regenerate-recovery-codes');

// Intencionalmente NO existen:
//  - POST /register                          (sin registro público)
//  - DELETE /user/two-factor-authentication  (el 2FA es obligatorio)
