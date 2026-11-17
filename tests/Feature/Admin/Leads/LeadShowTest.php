<?php

namespace Tests\Feature\Admin\Leads;

use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/** GET /api/admin/leads/{MS-XXXXXX} — detalle, historial y notas. */
class LeadShowTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Luis Admin', 'email' => 'luis@mykesystems.test']);
        $this->actingAs($this->admin);
    }

    public function test_muestra_el_detalle_completo(): void
    {
        $lead = Lead::factory()->create([
            'first_name' => 'Ana', 'last_name' => 'Prueba', 'email' => 'ana@ejemplo.test', 'phone' => '+50499990000',
            'business' => 'Ferretería Central', 'service' => 'inventario-facturacion',
            'message' => 'Necesito controlar el inventario de dos sucursales.', 'source' => 'hero',
        ]);

        $this->adminGet("/leads/{$lead->public_id}")->assertOk()->assertJson(['data' => [
            'id' => $lead->public_id,
            'status' => ['value' => 'nueva', 'label' => 'Nueva'],
            'service' => ['value' => 'inventario-facturacion', 'label' => 'Inventario y facturación'],
            'contact' => [
                'first_name' => 'Ana', 'last_name' => 'Prueba', 'full_name' => 'Ana Prueba',
                'email' => 'ana@ejemplo.test', 'phone' => '+50499990000', 'business' => 'Ferretería Central',
            ],
            'message' => 'Necesito controlar el inventario de dos sucursales.',
            'source' => 'hero',
            'anonymized' => false,
            'history' => [],
            'notes' => [],
            'permissions' => ['update_status' => true, 'add_note' => true],
        ]]);
    }

    public function test_incluye_historial_y_notas_con_autor_y_fecha(): void
    {
        $lead = Lead::factory()->create();
        $lead->statusChanges()->create(['user_id' => null, 'from_status' => null, 'to_status' => LeadStatus::Nueva]);
        $lead->statusChanges()->create(['user_id' => $this->admin->id, 'from_status' => LeadStatus::Nueva, 'to_status' => LeadStatus::Contactado]);
        $lead->notes()->create(['user_id' => $this->admin->id, 'body' => 'Primera nota']);
        $lead->notes()->create(['user_id' => $this->admin->id, 'body' => 'Segunda nota']);

        $data = $this->adminGet("/leads/{$lead->public_id}")->assertOk()->json('data');

        $this->assertNull($data['history'][0]['changed_by'], 'Registrado por el sistema');
        $this->assertNull($data['history'][0]['from']);
        $this->assertSame(['name' => 'Luis Admin'], $data['history'][1]['changed_by']);
        $this->assertSame('contactado', $data['history'][1]['to']['value']);
        $this->assertNotNull($data['history'][1]['changed_at']);

        $this->assertSame(['Segunda nota', 'Primera nota'], array_column($data['notes'], 'body'), 'Notas más recientes primero');
        $this->assertSame(['name' => 'Luis Admin'], $data['notes'][0]['author']);
        $this->assertNotNull($data['notes'][0]['created_at']);
    }

    public function test_no_expone_datos_internos(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->admin->id]);
        $lead->notes()->create(['user_id' => $this->admin->id, 'body' => 'Nota']);
        $lead->statusChanges()->create(['user_id' => $this->admin->id, 'from_status' => LeadStatus::Nueva, 'to_status' => LeadStatus::Contactado]);

        $response = $this->adminGet("/leads/{$lead->public_id}")->assertOk();
        $data = $response->json('data');

        foreach (['assigned_to', 'deleted_at', 'anonymized_at', 'lead_id', 'user_id'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertSame($lead->public_id, $data['id']);
        $this->assertSame(['name'], array_keys($data['notes'][0]['author']));
        $this->assertSame(['body', 'author', 'created_at'], array_keys($data['notes'][0]));
        $this->assertStringNotContainsString('luis@mykesystems.test', $response->getContent(), 'Nunca el correo del administrador');
        $this->assertStringNotContainsString('"id":'.$lead->id.',', $response->getContent());
    }

    public function test_registra_la_consulta_en_la_auditoria_sin_datos_personales(): void
    {
        $lead = Lead::factory()->create(['first_name' => 'Graciela', 'email' => 'cliente@ejemplo.test', 'phone' => '+50433334444']);

        $this->adminGet("/leads/{$lead->public_id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'lead.viewed', 'user_id' => $this->admin->id,
            'subject_type' => 'Lead', 'subject_id' => $lead->public_id,
        ]);
        $dump = json_encode(AuditLog::all()->map->getAttributes());
        foreach (['cliente@ejemplo.test', '+50433334444', $lead->first_name] as $personal) {
            $this->assertStringNotContainsString($personal, $dump);
        }
    }

    /* WhatsApp (retirado) -------------------------------------------- */

    public function test_no_expone_whatsapp_aunque_la_solicitud_tenga_consentimiento_historico(): void
    {
        $lead = Lead::factory()->withLegacyWhatsappConsent()->create(['phone' => '+50499990000']);

        $detail = $this->adminGet("/leads/{$lead->public_id}")->assertOk();
        $this->assertArrayNotHasKey('whatsapp', $detail->json('data'));
        $this->assertStringNotContainsString('wa.me', $detail->getContent());

        $item = $this->adminGet('/leads')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('whatsapp_consent', $item);

        // El dato histórico se conserva en la base de datos.
        $this->assertTrue($lead->fresh()->whatsapp_consent);
    }

    public function test_una_solicitud_anonimizada_no_admite_cambios(): void
    {
        $lead = Lead::factory()->anonymized()->create();

        $this->adminGet("/leads/{$lead->public_id}")->assertOk()->assertJson(['data' => [
            'anonymized' => true,
            'permissions' => ['update_status' => false, 'add_note' => false],
        ]]);
    }

    /* No encontrada ---------------------------------------------------- */

    public function test_solicitud_inexistente_o_eliminada_responde_404(): void
    {
        $deleted = Lead::factory()->create();
        $deleted->delete();

        foreach (['MS-ZZZZZZ', 'MS-123', 'no-existe', $deleted->public_id, (string) $deleted->id] as $id) {
            $response = $this->adminGet("/leads/{$id}")->assertNotFound()->assertJson(['ok' => false, 'code' => 'not_found']);
            $this->assertStringNotContainsString('App\\\\Models', $response->getContent(), "Sin nombres de clases ({$id})");
        }
    }
}
