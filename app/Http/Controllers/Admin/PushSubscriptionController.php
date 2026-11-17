<?php

namespace App\Http\Controllers\Admin;

use App\Audit\AuditLogger;
use App\Http\AdminErrorResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushMessage;
use App\Push\PushNotifier;
use App\Push\WebPushCrypto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Notificaciones push del panel: cada administrador gestiona las de sus propios dispositivos.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly PushNotifier $notifier) {}

    /** GET /push — clave pública VAPID y dispositivos suscritos del usuario. */
    public function config(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'available' => $this->notifier->isConfigured(),
            'public_key' => $this->notifier->isConfigured() ? config('mykesystems.push.public_key') : null,
            'can_month_end' => $user->can('manage-finances'),
            'devices' => PushSubscription::query()->where('user_id', $user->id)->orderByDesc('updated_at')->get()
                ->map(fn (PushSubscription $s) => [
                    'id' => $s->id,
                    'device' => $s->device,
                    'created_at' => $s->created_at?->toIso8601String(),
                    'last_success_at' => $s->last_success_at?->toIso8601String(),
                ])->values(),
        ]]);
    }

    /** POST /push/subscription/status — ¿este navegador está suscrito? y sus preferencias. */
    public function status(PushSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->own($request);

        return response()->json(['data' => [
            'subscribed' => $subscription !== null,
            'preferences' => $subscription?->preferences(),
        ]]);
    }

    /** PUT /push/subscription — alta o actualización de la suscripción de este navegador. */
    public function upsert(PushSubscriptionRequest $request): JsonResponse
    {
        if (! $this->notifier->isConfigured()) {
            return AdminErrorResponse::make(503, code: 'push_unavailable', message: 'Las notificaciones no están configuradas en el servidor.');
        }

        /** @var User $user */
        $user = $request->user();
        $v = $request->validated();

        try {
            // Valida que las claves sean utilizables antes de guardarlas.
            WebPushCrypto::encrypt('{}', $v['keys']['p256dh'], $v['keys']['auth']);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Datos de la suscripción no válidos.', 'errors' => ['keys' => ['Datos de la suscripción no válidos.']]], 422);
        }

        $hash = PushSubscription::hashEndpoint($v['endpoint']);
        $existing = PushSubscription::query()->where('endpoint_hash', $hash)->first();
        $prefs = $v['preferences'] ?? [];

        // Si el navegador era de otra cuenta (otro inicio de sesión en el mismo equipo), pasa a esta.
        $subscription = $existing ?? new PushSubscription;
        $subscription->fill([
            'user_id' => $user->id,
            'endpoint_hash' => $hash,
            'endpoint' => $v['endpoint'],
            'public_key' => $v['keys']['p256dh'],
            'auth_token' => $v['keys']['auth'],
            'device' => $v['device'] ?? $subscription->device,
            'notify_new_leads' => (bool) ($prefs['new_leads'] ?? $subscription->notify_new_leads ?? true),
            'notify_stale_leads' => (bool) ($prefs['stale_leads'] ?? $subscription->notify_stale_leads ?? true),
            // El recordatorio de fin de mes es de Finanzas: solo el propietario.
            'notify_month_end' => $user->can('manage-finances') && (bool) ($prefs['month_end'] ?? $subscription->notify_month_end ?? true),
        ]);
        $subscription->failures = 0;
        $subscription->save();

        if (! $existing || $existing->wasChanged('user_id')) {
            AuditLogger::record('push.subscribed', $user, $subscription);
        }

        return response()->json(['data' => ['subscribed' => true, 'preferences' => $subscription->preferences()]], $existing ? 200 : 201);
    }

    /** DELETE /push/subscription — desactiva las notificaciones en este navegador. */
    public function destroy(PushSubscriptionRequest $request): JsonResponse
    {
        $subscription = $this->own($request);
        if ($subscription) {
            $subscription->delete();
            AuditLogger::record('push.unsubscribed', $request->user(), $subscription);
        }

        return response()->json(['data' => ['subscribed' => false, 'preferences' => null]]);
    }

    /** POST /push/test — envía una notificación de prueba a este navegador (o a todos los del usuario). */
    public function test(PushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $query = PushSubscription::query()->where('user_id', $user->id);
        if ($endpoint = $request->validated('endpoint')) {
            $query->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint));
        }
        if (! $query->clone()->exists()) {
            return AdminErrorResponse::make(404, code: 'push_not_subscribed', message: 'Activa primero las notificaciones en este dispositivo.');
        }

        $result = $this->notifier->send($query, new PushMessage('MykeSystems', 'Las notificaciones funcionan en este dispositivo.', '/notificaciones', 'prueba'));

        return response()->json(['data' => $result]);
    }

    private function own(PushSubscriptionRequest $request): ?PushSubscription
    {
        return PushSubscription::query()
            ->where('user_id', $request->user()->getKey())
            ->where('endpoint_hash', PushSubscription::hashEndpoint((string) $request->validated('endpoint')))
            ->first();
    }
}
