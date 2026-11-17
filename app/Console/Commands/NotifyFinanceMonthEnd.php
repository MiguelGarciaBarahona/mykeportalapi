<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\PushSubscription;
use App\Push\PushMessage;
use App\Push\PushNotifier;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/** El último día del mes recuerda al propietario revisar su reporte de finanzas. */
class NotifyFinanceMonthEnd extends Command
{
    protected $signature = 'finance:notify-month-end {--force : Enviar aunque hoy no sea el último día del mes}';

    protected $description = 'Recordatorio push de cierre de mes (solo propietario)';

    public function handle(PushNotifier $notifier): int
    {
        $now = now((string) config('mykesystems.admin_api.timezone', 'UTC'));
        if (! $this->option('force') && ! $now->isLastOfMonth()) {
            $this->info('Hoy no es el último día del mes.');

            return self::SUCCESS;
        }

        $month = mb_strtolower($now->locale('es')->translatedFormat('F'));
        $result = $notifier->send(
            PushSubscription::query()
                ->where('notify_month_end', true)
                ->whereHas('user', fn (Builder $q) => $q->where('role', AdminRole::Owner->value)),
            new PushMessage('Cierre de mes', "Revisa tus finanzas y descarga el reporte de {$month}.", '/finanzas', 'cierre-de-mes'),
        );

        $this->info("Enviadas: {$result['sent']} · fallidas: {$result['failed']}");

        return self::SUCCESS;
    }
}
