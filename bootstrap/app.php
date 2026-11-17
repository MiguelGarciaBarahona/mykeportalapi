<?php

use App\Http\AdminErrorResponse;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureJsonRequest;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\VerifyPublicOrigin;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            $adminDomain = config('mykesystems.admin_domain');

            // Autenticación del panel (Fortify) — solo en admin.mykesystems.com
            $auth = Route::middleware(config('fortify.middleware', ['web']))->prefix('api/admin/auth');
            if ($adminDomain) {
                $auth->domain($adminDomain);
            }
            $auth->group(base_path('routes/admin-auth.php'));

            // API del panel — sesión vía Sanctum (modo SPA), solo en admin.mykesystems.com
            $admin = Route::middleware('api')->prefix('api/admin');
            if ($adminDomain) {
                $admin->domain($adminDomain);
            }
            $admin->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sesión + CSRF para el API solo cuando la petición viene de un dominio
        // "stateful" del panel (ver config/sanctum.php). El portal público queda sin sesión.
        $middleware->statefulApi();

        // Nunca aceptar peticiones "same-site" (mykesystems.com → admin.mykesystems.com) sin token CSRF.
        $middleware->preventRequestForgery(allowSameSite: false);

        $middleware->alias([
            'json.only' => EnsureJsonRequest::class,
            'public.origin' => VerifyPublicOrigin::class,
            'admin.active' => EnsureAdminIsActive::class,
            'admin.2fa' => EnsureTwoFactorConfirmed::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // El API siempre responde JSON (nunca páginas HTML de error).
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('sanctum/*') || $request->expectsJson()
        );

        // API del panel: errores con formato uniforme {ok:false, code, message}, sin trazas
        // ni nombres de clases o modelos. Los errores de validación (422) conservan el
        // formato estándar {message, errors}.
        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->is('api/admin/*')
            ? AdminErrorResponse::make(401)
            : null);

        $exceptions->render(fn (HttpExceptionInterface $e, Request $request) => $request->is('api/admin/*')
            ? AdminErrorResponse::make($e->getStatusCode(), $e->getHeaders())
            : null);
    })
    ->create();
