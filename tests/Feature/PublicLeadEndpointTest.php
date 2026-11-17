<?php

namespace Tests\Feature;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Notifications\NewLeadReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pruebas del endpoint público POST /api/v1/leads.
 * Los datos son ficticios y solo existen durante la prueba.
 */
class PublicLeadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://mykesystems.com';
    private const URL = '/api/v1/leads';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mykesystems.allowed_origins' => [self::ORIGIN],
            'mykesystems.notifications.admin_email' => 'solicitudes@mykesystems.com',
            'mykesystems.rate_limits' => [
                'per_ip_per_minute' => 100,
                'per_ip_per_hour' => 100,
                'global_per_day' => 1000,
            ],
        ]);

        Notification::fake();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ana',
            'last_name' => 'Prueba',
            'phone' => '9999-0000',
            'email' => 'Ana@Ejemplo.com',
            'business' => 'Negocio de prueba',
            'service' => 'crm-erp',
            'message' => 'Necesito un sistema para gestionar clientes y ventas.',
            'source' => 'servicios:crm-erp',
            'started_at' => now()->subSeconds(30)->getTimestampMs(),
            'website' => '',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function send(array $overrides = [], ?string $origin = self::ORIGIN)
    {
        $headers = $origin ? ['Origin' => $origin] : [];

        return $this->withHeaders($headers)->postJson(self::URL, $this->payload($overrides));
    }

    /* ---------------------------------------------------------------
     | Registro correcto
     * ------------------------------------------------------------- */

    public function test_registra_una_solicitud_valida(): void
    {
        $response = $this->send();

        $response->assertCreated()->assertJsonStructure(['ok', 'id'])->assertJson(['ok' => true]);
        $this->assertMatchesRegularExpression('/^MS-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{6}$/', $response->json('id'));

        $lead = Lead::firstOrFail();
        $this->assertSame($response->json('id'), $lead->public_id);
        $this->assertSame('Ana', $lead->first_name);
        $this->assertSame('+50499990000', $lead->phone, 'El teléfono se normaliza a E.164');
        $this->assertSame('ana@ejemplo.com', $lead->email, 'El correo se guarda en minúsculas');
        $this->assertSame(LeadStatus::Nueva, $lead->status);
        $this->assertNotNull($lead->status_changed_at);
        $this->assertFalse($lead->whatsapp_consent);
        $this->assertNull($lead->whatsapp_consent_at);
        $this->assertNull($lead->consent_version);

        // Historial: estado inicial registrado por el sistema.
        $this->assertDatabaseHas('lead_status_changes', [
            'lead_id' => $lead->id,
            'user_id' => null,
            'from_status' => null,
            'to_status' => 'nueva',
        ]);
    }

    public function test_la_respuesta_no_expone_datos_personales_ni_internos(): void
    {
        $response = $this->send();

        $this->assertSame(['ok', 'id'], array_keys($response->json()));
        $this->assertStringNotContainsString('solicitudes@mykesystems.com', $response->getContent());
        $this->assertStringNotContainsString('ana@ejemplo.com', $response->getContent());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function camposDeWhatsappRetirados(): array
    {
        return [
            'consentimiento con versión' => [['whatsapp_consent' => true, 'consent_version' => 'whatsapp-v1']],
            'sin consentimiento' => [['whatsapp_consent' => false, 'consent_version' => null]],
            'valores inválidos' => [['whatsapp_consent' => 'quizás', 'consent_version' => 'inventada']],
        ];
    }

    /**
     * WhatsApp ya no se usa: los campos de versiones anteriores del portal se aceptan
     * sin error, pero nunca se guardan.
     *
     * @param  array<string, mixed>  $legacy
     */
    #[DataProvider('camposDeWhatsappRetirados')]
    public function test_ignora_los_campos_de_whatsapp_de_versiones_anteriores(array $legacy): void
    {
        $this->send($legacy)->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertFalse($lead->whatsapp_consent);
        $this->assertNull($lead->whatsapp_consent_at);
        $this->assertNull($lead->consent_version);
    }

    public function test_el_negocio_es_opcional(): void
    {
        $this->send(['business' => null])->assertCreated();
        $this->send(['business' => '   '])->assertCreated();
        $this->assertSame(0, Lead::whereNotNull('business')->count());
    }

    public function test_envia_notificacion_interna_sin_datos_personales(): void
    {
        $this->send()->assertCreated();
        $lead = Lead::firstOrFail();

        Notification::assertSentOnDemand(
            NewLeadReceived::class,
            function (NewLeadReceived $notification, array $channels, AnonymousNotifiable $notifiable) use ($lead) {
                $mail = $notification->toMail($notifiable);
                $text = implode(' ', array_merge([$mail->subject], $mail->introLines, $mail->outroLines));

                return $notifiable->routes['mail'] === 'solicitudes@mykesystems.com'
                    && str_contains($text, $lead->public_id)
                    && ! str_contains($text, 'Ana')
                    && ! str_contains($text, 'Prueba')
                    && ! str_contains($text, 'ana@ejemplo.com')
                    && ! str_contains($text, '9999')
                    && ! str_contains($text, 'gestionar clientes');
            }
        );
    }

    public function test_no_notifica_si_no_hay_correo_interno_configurado(): void
    {
        config(['mykesystems.notifications.admin_email' => null]);

        $this->send()->assertCreated();
        Notification::assertNothingSent();
    }

    /* ---------------------------------------------------------------
     | Validación del lado del servidor
     * ------------------------------------------------------------- */

    public function test_rechaza_campos_obligatorios_vacios(): void
    {
        $this->withHeaders(['Origin' => self::ORIGIN])
            ->postJson(self::URL, ['started_at' => now()->subSeconds(30)->getTimestampMs()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'phone', 'email', 'service', 'message']);

        $this->assertDatabaseCount('leads', 0);
    }

    /** @return array<string, array{string, mixed}> */
    public static function datosInvalidos(): array
    {
        return [
            'nombre con números' => ['first_name', 'Ana123'],
            'nombre con enlace' => ['first_name', 'http://spam.example'],
            'nombre de una letra' => ['first_name', 'A'],
            'apellido muy largo' => ['last_name', str_repeat('a', 61)],
            'correo inválido' => ['email', 'no-es-correo'],
            'teléfono con letras' => ['phone', '9999-abcd'],
            'teléfono corto' => ['phone', '12345'],
            'teléfono ambiguo sin código' => ['phone', '4155552671'],
            'servicio inexistente' => ['service', 'hackeo'],
            'descripción corta' => ['message', 'Hola'],
            'descripción larga' => ['message', str_repeat('a', 2001)],
            'origen con caracteres raros' => ['source', '<script>'],
            'negocio muy largo' => ['business', str_repeat('n', 101)],
        ];
    }

    #[DataProvider('datosInvalidos')]
    public function test_rechaza_datos_invalidos(string $field, mixed $value): void
    {
        $this->send([$field => $value])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_acepta_numeros_internacionales_con_codigo(): void
    {
        $this->send(['phone' => '+1 (415) 555-2671'])->assertCreated();
        $this->assertSame('+14155552671', Lead::firstOrFail()->phone);
    }

    public function test_limpia_caracteres_de_control_y_espacios(): void
    {
        $this->send([
            'first_name' => "  Ana\u{0000}  ",
            'last_name' => "Prueba\u{202E}",
            'message' => "Necesito un sistema\u{0007} para   clientes.\r\n\r\n\r\n\r\nGracias por la ayuda.",
        ])->assertCreated();

        $lead = Lead::firstOrFail();
        $this->assertSame('Ana', $lead->first_name);
        $this->assertSame('Prueba', $lead->last_name);
        $this->assertSame("Necesito un sistema para clientes.\n\nGracias por la ayuda.", $lead->message);
    }

    /* ---------------------------------------------------------------
     | Antispam
     * ------------------------------------------------------------- */

    public function test_bloquea_el_campo_trampa(): void
    {
        $this->send(['website' => 'https://spam.example'])
            ->assertUnprocessable()->assertJson(['ok' => false, 'code' => 'rejected']);
        $this->assertDatabaseCount('leads', 0);
        Notification::assertNothingSent();
    }

    public function test_bloquea_envios_demasiado_rapidos(): void
    {
        $this->send(['started_at' => now()->subMilliseconds(800)->getTimestampMs()])
            ->assertUnprocessable()->assertJson(['code' => 'rejected']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_bloquea_formularios_con_marca_de_tiempo_absurda(): void
    {
        $this->send(['started_at' => now()->subDays(3)->getTimestampMs()])->assertJson(['code' => 'rejected']);
        $this->send(['started_at' => now()->addHour()->getTimestampMs()])->assertJson(['code' => 'rejected']);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_bloquea_descripciones_con_demasiados_enlaces(): void
    {
        $this->send(['message' => 'Visita https://a.example y https://b.example y www.c.example para más información.'])
            ->assertJson(['code' => 'rejected']);
        $this->assertDatabaseCount('leads', 0);
    }

    /* ---------------------------------------------------------------
     | Origen, formato y método
     * ------------------------------------------------------------- */

    public function test_rechaza_origen_ausente_o_ajeno(): void
    {
        $this->send([], null)->assertForbidden()->assertJson(['code' => 'forbidden_origin']);
        $this->send([], 'https://sitio-malicioso.example')->assertForbidden();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_rechaza_contenido_que_no_es_json(): void
    {
        $this->withHeaders(['Origin' => self::ORIGIN, 'Accept' => 'application/json'])
            ->post(self::URL, $this->payload())
            ->assertStatus(415);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_rechaza_cuerpos_demasiado_grandes(): void
    {
        $this->send(['business' => str_repeat('x', 11_000)])->assertStatus(413);
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_solo_acepta_post(): void
    {
        $this->withHeaders(['Origin' => self::ORIGIN])->getJson(self::URL)->assertStatus(405);
    }

    /* ---------------------------------------------------------------
     | Límites de solicitudes
     * ------------------------------------------------------------- */

    public function test_limita_solicitudes_por_ip(): void
    {
        config(['mykesystems.rate_limits.per_ip_per_hour' => 2]);

        $this->send()->assertCreated();
        $this->send()->assertCreated();
        $this->send()
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJson(['ok' => false, 'code' => 'too_many_requests']);

        $this->assertDatabaseCount('leads', 2);
    }

    public function test_el_limite_cuenta_tambien_los_intentos_invalidos(): void
    {
        config(['mykesystems.rate_limits.per_ip_per_minute' => 2]);

        $this->send([], 'https://sitio-malicioso.example')->assertForbidden();
        $this->send(['email' => 'malo'])->assertUnprocessable();
        $this->send()->assertStatus(429);
    }

    public function test_aplica_un_tope_global_diario(): void
    {
        config(['mykesystems.rate_limits.global_per_day' => 1]);

        $this->send()->assertCreated();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->send()->assertStatus(429);
    }
}
