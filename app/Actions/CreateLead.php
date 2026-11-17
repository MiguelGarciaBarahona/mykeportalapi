<?php

namespace App\Actions;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\PushSubscription;
use App\Notifications\NewLeadReceived;
use App\Push\PushMessage;
use App\Push\PushNotifier;
use App\Support\PublicIdGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Registra una solicitud validada y avisa internamente (sin datos personales).
 */
final class CreateLead
{
    /**
     * @param  array{first_name:string,last_name:string,phone:string,email:string,business:?string,service:string,message:string,source:?string}  $data
     */
    public function handle(array $data): Lead
    {
        $lead = DB::transaction(function () use ($data): Lead {
            $now = now();

            $lead = Lead::create([
                'public_id' => PublicIdGenerator::generate(
                    fn (string $id) => Lead::withTrashed()->where('public_id', $id)->exists()
                ),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'business' => $data['business'] ?? null,
                'service' => $data['service'],
                'message' => $data['message'],
                'source' => $data['source'] ?? null,
                // Las columnas whatsapp_consent* se conservan solo por el historial:
                // las solicitudes nuevas usan sus valores predeterminados (false / null).
                'status' => LeadStatus::Nueva,
                'status_changed_at' => $now,
            ]);

            $lead->statusChanges()->create([
                'user_id' => null, // registrado por el sistema
                'from_status' => null,
                'to_status' => LeadStatus::Nueva,
            ]);

            return $lead;
        });

        $adminEmail = config('mykesystems.notifications.admin_email');
        if (is_string($adminEmail) && $adminEmail !== '') {
            Notification::route('mail', $adminEmail)->notify(new NewLeadReceived($lead->public_id, $lead->service));
        }

        // Notificación push a los administradores (después de responder al visitante: no lo hace esperar).
        $publicId = $lead->public_id;
        $serviceLabel = $lead->serviceLabel();
        dispatch(function () use ($publicId, $serviceLabel) {
            app(PushNotifier::class)->send(
                PushSubscription::query()->where('notify_new_leads', true),
                new PushMessage("Nueva solicitud {$publicId}", $serviceLabel, '/solicitudes/'.$publicId, 'lead-'.$publicId),
            );
        })->afterResponse();

        return $lead;
    }
}
