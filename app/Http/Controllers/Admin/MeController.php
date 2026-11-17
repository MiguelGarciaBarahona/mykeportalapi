<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/admin/me — datos mínimos del administrador autenticado. */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'two_factor_confirmed' => $user->hasConfirmedTwoFactor(),
            'permissions' => [
                'manage_admins' => $user->can('viewAny', User::class),
                'manage_finances' => $user->can('manage-finances'),
            ],
        ]);
    }
}
