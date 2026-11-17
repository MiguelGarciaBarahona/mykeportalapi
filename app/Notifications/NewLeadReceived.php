<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso interno de nueva solicitud.
 * Privacidad: NO incluye nombre, correo, teléfono ni descripción; solo el número
 * de solicitud, el servicio y un enlace al panel (que exige inicio de sesión).
 */
class NewLeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $publicId,
        public readonly string $service,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $serviceLabel = (string) (config('mykesystems.services')[$this->service] ?? $this->service);
        $panelUrl = config('mykesystems.notifications.admin_panel_url').'/solicitudes/'.$this->publicId;

        return (new MailMessage)
            ->subject("Nueva solicitud {$this->publicId}")
            ->greeting('Nueva solicitud recibida')
            ->line("Se registró la solicitud {$this->publicId} desde el portal.")
            ->line("Servicio de interés: {$serviceLabel}.")
            ->action('Ver en el panel', $panelUrl)
            ->line('Por privacidad, este correo no incluye datos personales. Consulta el detalle en el panel administrativo.');
    }
}
