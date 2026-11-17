<?php

namespace App\Auth;

use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Logout;

/**
 * Al cerrar sesión, descarta todas las instancias de guard ya resueltas.
 *
 * Fortify (y EnsureAdminIsActive) cierran sesión en el guard `web`, que limpia la
 * sesión y su propio usuario en memoria. Pero el guard `sanctum` que protege
 * /api/admin es un RequestGuard que memoriza el usuario resuelto en la primera
 * llamada a user() y no se entera del logout. En procesos que atienden más de una
 * petición (pruebas, Octane, workers) esa instancia sobrevive y seguiría
 * devolviendo al usuario después de cerrar sesión.
 *
 * Olvidar los guards obliga a que la siguiente petición los cree de nuevo y
 * resuelva al usuario desde la sesión, que ya fue invalidada.
 */
class ForgetResolvedGuards
{
    public function __construct(private readonly AuthManager $auth) {}

    public function handle(Logout $event): void
    {
        $this->auth->forgetGuards();
    }
}
