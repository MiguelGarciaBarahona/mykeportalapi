<?php

namespace Tests\Feature;

use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contrato con el formulario «Hablemos de tu proyecto» del portal (Fase E):
 * el JSON que genera mykesystems-web/src/lib/quote/adapters/http.ts debe aceptarse tal cual.
 */
class PortalFormContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mykesystems.allowed_origins' => ['https://mykesystems.com'],
            'mykesystems.notifications.admin_email' => null,
            'mykesystems.rate_limits' => ['per_ip_per_minute' => 100, 'per_ip_per_hour' => 100, 'global_per_day' => 1000],
        ]);
        Notification::fake();
    }

    /** @param array<string, mixed> $overrides */
    private function portalPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'José María',
            'last_name' => "O'Neil-Álvarez",
            'phone' => '+504 9999-0000',
            'email' => 'jose@ejemplo.test',
            'business' => null,
            'service' => 'inventario-facturacion',
            'message' => 'Necesito controlar el inventario de dos sucursales.',
            'source' => 'modal:inicio-hero',
            'started_at' => now()->subSeconds(20)->getTimestampMs(),
            'website' => '',
        ], $overrides);
    }

    /** @return array<string, array{string}> */
    public static function portalSources(): array
    {
        return [
            'cabecera' => ['cabecera'],
            'menú móvil' => ['menu-movil'],
            'héroe' => ['inicio-hero'],
            'banner final' => ['servicios-cta'],
            'categoría' => ['servicios:sistemas-empresariales'],
            'tarjeta de servicio' => ['servicios:crm-erp'],
            'página de contacto' => ['pagina-contacto'],
        ];
    }

    #[DataProvider('portalSources')]
    public function test_acepta_los_origenes_que_genera_el_portal(string $source): void
    {
        $this->withHeaders(['Origin' => 'https://mykesystems.com'])
            ->postJson('/api/v1/leads', $this->portalPayload(['source' => $source]))
            ->assertCreated()
            ->assertJson(['ok' => true]);

        $this->assertSame($source, Lead::firstOrFail()->source);
    }

    public function test_el_contrato_del_portal_no_incluye_whatsapp(): void
    {
        $this->withHeaders(['Origin' => 'https://mykesystems.com'])->postJson('/api/v1/leads', $this->portalPayload())->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertFalse($lead->whatsapp_consent, 'Las solicitudes nuevas nunca registran consentimiento de WhatsApp');
        $this->assertNull($lead->consent_version);
        $this->assertNull($lead->whatsapp_consent_at);
    }

    public function test_la_respuesta_solo_contiene_el_numero_de_solicitud(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://mykesystems.com'])->postJson('/api/v1/leads', $this->portalPayload())->assertCreated();

        $this->assertSame(['ok', 'id'], array_keys($response->json()));
        $this->assertStringNotContainsString('solicitudes@mykesystems.com', $response->getContent());
    }
}
