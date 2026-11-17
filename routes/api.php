<?php

use App\Http\Controllers\Public\LeadController;
use Illuminate\Support\Facades\Route;

/*
| API pública (mykesystems.com/api/v1) — sin sesión ni cookies.
| El orden importa: el límite de solicitudes cuenta TODOS los intentos,
| incluidos los inválidos o de orígenes no permitidos.
*/
Route::prefix('v1')
    ->middleware(['throttle:public-leads', 'json.only', 'public.origin'])
    ->group(function () {
        Route::post('leads', [LeadController::class, 'store'])->name('public.leads.store');
    });

// Las rutas del panel (/api/admin/*) se agregan en la Fase B.
