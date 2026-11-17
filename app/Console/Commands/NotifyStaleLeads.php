<?php

namespace App\Console\Commands;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\PushSubscription;
use App\Push\PushMessage;
use App\Push\PushNotifier;
use Illuminate\Console\Command;

/** Avisa (una sola vez por solicitud) de las que siguen en «Nueva» después de N horas. */
class NotifyStaleLeads extends Command
{
    protected $signature = 'leads:notify-stale';

    protected $description = 'Notificación push de solicitudes sin atender';

    public function handle(PushNotifier $notifier): int
    {
        $hours = max(1, (int) config('mykesystems.push.stale_lead_hours', 24));
        $leads = Lead::query()
            ->where('status', LeadStatus::Nueva->value)
            ->where('status_changed_at', '<=', now()->subHours($hours))
            ->whereNull('stale_notified_at')
            ->orderBy('status_changed_at')
            ->get(['id', 'public_id']);

        if ($leads->isEmpty()) {
            $this->info('No hay solicitudes pendientes de aviso.');

            return self::SUCCESS;
        }

        $message = $leads->count() === 1
            ? new PushMessage('Solicitud sin atender', "La solicitud {$leads->first()->public_id} lleva más de {$hours} h en «Nueva».", '/solicitudes/'.$leads->first()->public_id, 'leads-sin-atender')
            : new PushMessage('Solicitudes sin atender', "{$leads->count()} solicitudes llevan más de {$hours} h en «Nueva».", '/solicitudes?status=nueva', 'leads-sin-atender');

        $result = $notifier->send(PushSubscription::query()->where('notify_stale_leads', true), $message);
        // Se marcan aunque nadie tenga notificaciones activas: el aviso es una sola vez.
        Lead::query()->whereKey($leads->pluck('id'))->update(['stale_notified_at' => now()]);

        $this->info("Solicitudes: {$leads->count()} · enviadas: {$result['sent']} · fallidas: {$result['failed']}");

        return self::SUCCESS;
    }
}
