<?php

/*
|--------------------------------------------------------------------------
| API del panel — admin.mykesystems.com/api/admin
|--------------------------------------------------------------------------
| Middleware del grupo: "api" + Sanctum (sesión y CSRF para dominios del panel).
| Todas las rutas exigen sesión, cuenta activa y (salvo /me) 2FA confirmado.
*/

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\Finance\FinanceBookController;
use App\Http\Controllers\Admin\Finance\FinanceCategoryController;
use App\Http\Controllers\Admin\Finance\FinanceReportController;
use App\Http\Controllers\Admin\Finance\FinanceTransactionController;
use App\Http\Controllers\Admin\PushSubscriptionController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\MeController;
use Illuminate\Support\Facades\Route;

// Número público de solicitud (ver PublicIdGenerator::PATTERN).
$leadId = 'MS-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}';

Route::middleware(['auth:sanctum', 'admin.active'])->group(function () use ($leadId) {
    // Disponible sin 2FA: el panel la usa para saber si debe mostrar la configuración del 2FA.
    Route::get('/me', MeController::class)->name('admin.me');

    Route::middleware('admin.2fa')->group(function () use ($leadId) {
        // Gestión de administradores (solo owner, ver UserPolicy)
        Route::get('/admins', [AdminUserController::class, 'index'])->name('admin.admins.index');
        Route::patch('/admins/{admin}/deactivate', [AdminUserController::class, 'deactivate'])->name('admin.admins.deactivate');
        Route::patch('/admins/{admin}/activate', [AdminUserController::class, 'activate'])->name('admin.admins.activate');

        // Solicitudes (owner y admin, ver LeadPolicy)
        Route::middleware('throttle:admin-api')->group(function () use ($leadId) {
            Route::get('/dashboard', DashboardController::class)->name('admin.dashboard');
            Route::get('/leads', [LeadController::class, 'index'])->name('admin.leads.index');
            Route::get('/leads/options', [LeadController::class, 'options'])->name('admin.leads.options');
            Route::get('/leads/{lead}', [LeadController::class, 'show'])->where('lead', $leadId)->name('admin.leads.show');

            Route::middleware('throttle:admin-writes')->group(function () use ($leadId) {
                Route::patch('/leads/{lead}/status', [LeadController::class, 'updateStatus'])->where('lead', $leadId)->name('admin.leads.status');
                Route::post('/leads/{lead}/notes', [LeadController::class, 'storeNote'])->where('lead', $leadId)->name('admin.leads.notes.store');
            });
        });

        // Finanzas del propietario: libros (Personal, Empresa, …). Solo owner, ver Gate «manage-finances».
        Route::prefix('finance')->middleware(['can:manage-finances', 'throttle:admin-api'])->group(function () {
            Route::get('/books', [FinanceBookController::class, 'index'])->name('admin.finance.books.index');
            Route::middleware('throttle:admin-writes')->group(function () {
                Route::post('/books', [FinanceBookController::class, 'store'])->name('admin.finance.books.store');
                Route::patch('/books/{book}', [FinanceBookController::class, 'update'])->whereNumber('book')->name('admin.finance.books.update');
            });

            // Todo lo demás vive dentro de un libro; los movimientos y categorías deben pertenecer a él.
            Route::prefix('books/{book}')->whereNumber('book')->scopeBindings()->group(function () {
                Route::get('/', [FinanceBookController::class, 'show'])->name('admin.finance.books.show');
                Route::get('/options', [FinanceTransactionController::class, 'options'])->name('admin.finance.options');
                Route::get('/transactions', [FinanceTransactionController::class, 'index'])->name('admin.finance.transactions.index');
                Route::get('/transactions/{transaction}', [FinanceTransactionController::class, 'show'])->whereNumber('transaction')->name('admin.finance.transactions.show');
                Route::get('/transactions/{transaction}/receipt', [FinanceTransactionController::class, 'showReceipt'])->whereNumber('transaction')->name('admin.finance.receipt.show');
                Route::get('/report', [FinanceReportController::class, 'show'])->name('admin.finance.report');
                Route::get('/report/export', [FinanceReportController::class, 'export'])->name('admin.finance.report.export');

                Route::middleware('throttle:admin-writes')->group(function () {
                    Route::post('/transactions', [FinanceTransactionController::class, 'store'])->name('admin.finance.transactions.store');
                    Route::patch('/transactions/{transaction}', [FinanceTransactionController::class, 'update'])->whereNumber('transaction')->name('admin.finance.transactions.update');
                    Route::delete('/transactions/{transaction}', [FinanceTransactionController::class, 'destroy'])->whereNumber('transaction')->name('admin.finance.transactions.destroy');
                    Route::post('/transactions/{transaction}/receipt', [FinanceTransactionController::class, 'storeReceipt'])->whereNumber('transaction')->name('admin.finance.receipt.store');
                    Route::delete('/transactions/{transaction}/receipt', [FinanceTransactionController::class, 'destroyReceipt'])->whereNumber('transaction')->name('admin.finance.receipt.destroy');
                    Route::post('/categories', [FinanceCategoryController::class, 'store'])->name('admin.finance.categories.store');
                    Route::patch('/categories/{category}', [FinanceCategoryController::class, 'update'])->whereNumber('category')->name('admin.finance.categories.update');
                });
            });
        });

        // Notificaciones push del panel (cualquier administrador activo con 2FA, para su propio dispositivo)
        Route::prefix('push')->middleware('throttle:admin-api')->group(function () {
            Route::get('/', [PushSubscriptionController::class, 'config'])->name('admin.push.config');
            Route::middleware('throttle:admin-writes')->group(function () {
                Route::post('/subscription/status', [PushSubscriptionController::class, 'status'])->name('admin.push.status');
                Route::put('/subscription', [PushSubscriptionController::class, 'upsert'])->name('admin.push.upsert');
                Route::delete('/subscription', [PushSubscriptionController::class, 'destroy'])->name('admin.push.destroy');
                Route::post('/test', [PushSubscriptionController::class, 'test'])->name('admin.push.test');
            });
        });
    });
});
