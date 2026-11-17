<?php

namespace App\Http\Controllers\Admin;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\SessionTerminator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Gestión básica de administradores (solo owner). La gestión completa llega en fases siguientes. */
class AdminUserController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        $admins = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'two_factor_confirmed_at', 'last_login_at'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'is_active' => $u->is_active,
                'two_factor_confirmed' => $u->hasConfirmedTwoFactor(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $admins]);
    }

    public function deactivate(Request $request, User $admin): JsonResponse
    {
        Gate::authorize('deactivate', $admin);

        $admin->forceFill(['is_active' => false])->save();
        SessionTerminator::forUser($admin);
        AuditLogger::record('admin.deactivated', $request->user(), $admin, (string) $admin->id);

        return response()->json(['ok' => true]);
    }

    public function activate(Request $request, User $admin): JsonResponse
    {
        Gate::authorize('activate', $admin);

        $admin->forceFill(['is_active' => true])->save();
        AuditLogger::record('admin.activated', $request->user(), $admin, (string) $admin->id);

        return response()->json(['ok' => true]);
    }
}
