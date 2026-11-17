<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LeadStatus;
use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/admin/dashboard — estadísticas calculadas en el momento a partir de las
 * solicitudes registradas (sin datos de ejemplo). Las solicitudes eliminadas
 * (borrado lógico) no se cuentan.
 */
class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $now = now();

        // 1) Conteo por estado (una consulta agrupada).
        $byStatus = Lead::query()->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $count = fn (LeadStatus ...$statuses) => array_sum(array_map(fn (LeadStatus $s) => $byStatus[$s->value] ?? 0, $statuses));

        // 2) Recepción reciente (una consulta).
        $recent = Lead::query()->toBase()
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS last_7_days, '.
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS last_30_days',
                [$now->copy()->subDays(7), $now->copy()->subDays(30)]
            )
            ->first();

        return response()->json([
            'data' => [
                'totals' => [
                    'total' => $byStatus->sum(),
                    'nuevas' => $count(LeadStatus::Nueva),
                    'en_proceso' => $count(LeadStatus::Contactado, LeadStatus::EnNegociacion),
                    'aceptadas' => $count(LeadStatus::Aceptada),
                    'cerradas' => $count(LeadStatus::Cerrada),
                ],
                'by_status' => array_map(fn (LeadStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
                    'count' => $byStatus[$s->value] ?? 0,
                ], LeadStatus::cases()),
                'received' => [
                    'last_7_days' => (int) ($recent->last_7_days ?? 0),
                    'last_30_days' => (int) ($recent->last_30_days ?? 0),
                ],
                'generated_at' => $now->toIso8601String(),
            ],
        ]);
    }
}
