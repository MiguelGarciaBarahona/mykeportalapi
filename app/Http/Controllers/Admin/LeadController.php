<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\AddLeadNote;
use App\Actions\Admin\ChangeLeadStatus;
use App\Audit\AuditLogger;
use App\Enums\LeadStatus;
use App\Http\AdminErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LeadIndexRequest;
use App\Http\Requests\Admin\StoreLeadNoteRequest;
use App\Http\Requests\Admin\UpdateLeadStatusRequest;
use App\Http\Resources\Admin\LeadDetailResource;
use App\Http\Resources\Admin\LeadListResource;
use App\Http\Resources\Admin\LeadNoteResource;
use App\Http\Resources\Admin\LeadStatusChangeResource;
use App\Models\Lead;
use App\Queries\AdminLeadQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API de solicitudes del panel (owner y admin, ver LeadPolicy).
 * Formato: éxito {data[, meta]} · error {ok:false, code, message} · validación {message, errors}.
 */
class LeadController extends Controller
{
    /** GET /leads */
    public function index(LeadIndexRequest $request): JsonResponse
    {
        $page = AdminLeadQuery::build($request->filters())->paginate($request->perPage());

        return response()->json([
            'data' => LeadListResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    /** GET /leads/options — valores para los filtros del panel. */
    public function options(): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return response()->json([
            'data' => [
                'statuses' => array_map(fn (LeadStatus $s) => ['value' => $s->value, 'label' => $s->label()], LeadStatus::cases()),
                'status_groups' => array_map(
                    fn (string $group, array $statuses) => [
                        'value' => $group,
                        'label' => 'En proceso',
                        'statuses' => array_map(fn (LeadStatus $s) => $s->value, $statuses),
                    ],
                    array_keys(AdminLeadQuery::STATUS_GROUPS),
                    AdminLeadQuery::STATUS_GROUPS,
                ),
                'services' => array_map(
                    fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                    array_keys((array) config('mykesystems.services')),
                    (array) config('mykesystems.services'),
                ),
                'sorts' => array_keys(AdminLeadQuery::SORTS),
                'per_page_max' => (int) config('mykesystems.admin_api.per_page_max', 100),
            ],
        ]);
    }

    /** GET /leads/{lead} — detalle completo (la consulta queda auditada). */
    public function show(Request $request, Lead $lead): JsonResponse
    {
        Gate::authorize('view', $lead);

        AuditLogger::record('lead.viewed', $request->user(), $lead);

        return response()->json(['data' => (new LeadDetailResource(self::loadDetail($lead)))->resolve($request)]);
    }

    /** PATCH /leads/{lead}/status */
    public function updateStatus(UpdateLeadStatusRequest $request, Lead $lead, ChangeLeadStatus $action): JsonResponse
    {
        if ($lead->isAnonymized()) {
            return self::anonymizedConflict();
        }

        $change = $action->handle($lead, $request->status(), $request->user());
        $change->setRelation('user', $request->user());

        return response()->json([
            'data' => [
                'id' => $lead->public_id,
                'status' => ['value' => $lead->status->value, 'label' => $lead->status->label()],
                'status_changed_at' => $lead->status_changed_at?->toIso8601String(),
                'change' => (new LeadStatusChangeResource($change))->resolve($request),
            ],
        ]);
    }

    /** POST /leads/{lead}/notes */
    public function storeNote(StoreLeadNoteRequest $request, Lead $lead, AddLeadNote $action): JsonResponse
    {
        if ($lead->isAnonymized()) {
            return self::anonymizedConflict();
        }

        $note = $action->handle($lead, $request->body(), $request->user());

        return response()->json(['data' => (new LeadNoteResource($note))->resolve($request)], 201);
    }

    private static function loadDetail(Lead $lead): Lead
    {
        return $lead->load([
            'statusChanges' => fn ($q) => $q->orderBy('id'),
            'statusChanges.user:id,name',
            'notes' => fn ($q) => $q->orderByDesc('id'),
            'notes.author:id,name',
        ]);
    }

    private static function anonymizedConflict(): JsonResponse
    {
        return AdminErrorResponse::make(409, code: 'lead_anonymized',
            message: 'La solicitud fue anonimizada y ya no admite cambios.');
    }
}
