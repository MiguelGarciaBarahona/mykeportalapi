<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
| Laravel Sanctum — modo SPA (sesión por cookie), sin tokens en el navegador.
| Solo los dominios del panel son "stateful": el portal público (mykesystems.com)
| nunca inicia sesión ni recibe cookies del API.
*/

return [
    // ⚠️ Nunca incluir mykesystems.com aquí.
    'stateful' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        'admin.mykesystems.com'
    ))))),

    'guard' => ['web'],

    // No se usan tokens personales; si alguno existiera, caducaría en 1 hora.
    'expiration' => 60,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => PreventRequestForgery::class,
    ],
];
