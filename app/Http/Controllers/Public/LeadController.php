<?php

namespace App\Http\Controllers\Public;

use App\Actions\CreateLead;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeadRequest;
use App\Support\SpamGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/leads — registra una solicitud del formulario público.
 *
 * Respuestas:
 *  201 {ok:true, id:"MS-XXXXXX"}            solicitud guardada
 *  422 {message, errors:{campo:[...]}}      datos inválidos
 *  422 {ok:false, code:"rejected"}          bloqueada por antispam
 *  403 forbidden_origin · 413 · 415 · 429 (Retry-After) · 500
 */
class LeadController extends Controller
{
    public function store(StoreLeadRequest $request, CreateLead $createLead): JsonResponse
    {
        $antispam = config('mykesystems.antispam');
        $guard = new SpamGuard(
            minFillSeconds: (int) $antispam['min_fill_seconds'],
            maxFormAgeHours: (int) $antispam['max_form_age_hours'],
            maxLinks: (int) $antispam['max_links'],
        );

        $reason = $guard->check(
            honeypot: $request->input($antispam['honeypot_field']),
            startedAtMs: $request->integer('started_at'),
            message: (string) $request->input('message'),
            nowMs: now()->getTimestampMs(),
        );

        if ($reason !== null) {
            // Se registra solo el motivo, nunca los datos enviados.
            Log::info('Solicitud pública rechazada por antispam', ['reason' => $reason]);

            return response()->json([
                'ok' => false,
                'code' => 'rejected',
                'message' => 'No pudimos procesar tu solicitud. Revisa los datos e inténtalo de nuevo.',
            ], 422);
        }

        $lead = $createLead->handle($request->leadData());

        // Solo se devuelve el número de solicitud: ningún dato personal ni interno.
        return response()->json(['ok' => true, 'id' => $lead->public_id], 201);
    }
}
