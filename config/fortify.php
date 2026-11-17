<?php

use Laravel\Fortify\Features;

/*
| Laravel Fortify — autenticación del panel administrativo (admin.mykesystems.com).
| Las rutas de Fortify NO se registran automáticamente (Fortify::ignoreRoutes()):
| se definen en routes/admin-auth.php para controlar exactamente qué existe.
*/

return [
    'guard' => 'web',
    'middleware' => ['web'],
    'auth_middleware' => 'auth',
    'passwords' => 'users',

    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,

    'home' => '/',
    'prefix' => 'api/admin/auth',
    'domain' => env('ADMIN_DOMAIN'),

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    // El panel es una SPA: Fortify responde JSON y no renderiza vistas.
    'views' => false,

    'features' => [
        // Sin registro público: los administradores se crean con `php artisan admin:create`.
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,          // el 2FA solo se activa tras validar un código
            'confirmPassword' => true,  // gestionar el 2FA exige confirmar la contraseña
        ]),
    ],
];
