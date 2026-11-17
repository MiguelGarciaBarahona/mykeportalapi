<?php

namespace Tests\Feature\Admin\Leads;

use App\Enums\LeadStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/** Cambio de estado con historial y notas internas. */
class LeadUpdatesTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->withTwoFactor()->create(['name' => 'Luis Admin']);
        $this->actingAs($this->admin);
    }

    /* Estados ---------------------------------------------------------- */

    public function test_cambia_el_estado_y_registra_el_historial(): void
    {
        $lead = Lead::factory()->create(['status_changed_at' => now()->subDays(2)]);

        $this->adminPatch("/leads/{$lead->public_id}/status", ['status' => 'contactado'])->assertOk()->assertJson(['data' => [
            'id' => $lead->public_id,
            'status' => ['value' => 'contactado', 'label' => 'Contactado'],
            'change' => [
                'from' => ['value' => 'nueva'],
                'to' => ['value' => 'contactado'],
                'changed_by' => ['name' => 'Luis Admin'],
            ],
        ]]);

        $lead->refresh();
        $this->assertSame(LeadStatus::Contactado, $lead->status);
        $this->assertTrue($lead->status_changed_at->isToday());
        $this->assertDatabaseHas('lead_status_changes', [
            'lead_id' => $lead->id, 'user_id' => $this->admin->id, 'from_status' => 'nueva', 'to_status' => 'contactado',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.status_changed', 'user_id' => $this->admin->id, 'subject_id' => $lead->public_id]);
    }

    public function test_el_historial_conserva_todos_los_cambios_en_orden(): void
    {
        $lead = Lead::factory()->create();

        foreach (['contactado', 'en_negociacion', 'aceptada'] as $status) {
            $this->adminPatch("/leads/{$lead->public_id}/status", ['status' => $status])->assertOk();
        }

        $history = $this->adminGet("/leads/{$lead->public_id}")->json('data.history');
        $this->assertSame(['contactado', 'en_negociacion', 'aceptada'], array_column(array_column($history, 'to'), 'value'));
        $this->assertSame(['nueva', 'contactado', 'en_negociacion'], array_column(array_column($history, 'from'), 'value'));
    }

    public function test_rechaza_el_mismo_estado_o_un_estado_invalido(): void
    {
        $lead = Lead::factory()->create();

        $this->adminPatch("/leads/{$lead->public_id}/status", ['status' => 'nueva'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->adminPatch("/leads/{$lead->public_id}/status", ['status' => 'archivada'])->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->adminPatch("/leads/{$lead->public_id}/status", [])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->assertDatabaseCount('lead_status_changes', 0);
        $this->assertSame(LeadStatus::Nueva, $lead->fresh()->status);
    }

    public function test_una_solicitud_anonimizada_no_cambia_de_estado(): void
    {
        $lead = Lead::factory()->anonymized()->create();

        $this->adminPatch("/leads/{$lead->public_id}/status", ['status' => 'cerrada'])
            ->assertStatus(409)->assertJson(['ok' => false, 'code' => 'lead_anonymized']);

        $this->assertSame(LeadStatus::Nueva, $lead->fresh()->status);
        $this->assertDatabaseCount('lead_status_changes', 0);
    }

    public function test_cambiar_el_estado_de_una_solicitud_inexistente_responde_404(): void
    {
        $this->adminPatch('/leads/MS-ZZZZZZ/status', ['status' => 'cerrada'])->assertNotFound()->assertJson(['code' => 'not_found']);
    }

    /* Notas ------------------------------------------------------------ */

    public function test_agrega_una_nota_con_autor_y_fecha(): void
    {
        $lead = Lead::factory()->create();

        $response = $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => "  Llamar el lunes.\r\n\r\n\r\n\r\nPrefiere   la tarde.  "])
            ->assertCreated()
            ->assertJson(['data' => ['body' => "Llamar el lunes.\n\nPrefiere la tarde.", 'author' => ['name' => 'Luis Admin']]]);

        $this->assertNotNull($response->json('data.created_at'));
        $this->assertDatabaseHas('lead_notes', ['lead_id' => $lead->id, 'user_id' => $this->admin->id]);
        $this->assertCount(1, $this->adminGet("/leads/{$lead->public_id}")->json('data.notes'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'lead.note_added', 'user_id' => $this->admin->id, 'subject_id' => $lead->public_id]);
        $this->assertStringNotContainsString('Llamar el lunes', json_encode(AuditLog::all()->map->getAttributes()), 'La auditoría no guarda el contenido');
    }

    public function test_el_contenido_de_la_nota_se_guarda_como_texto(): void
    {
        $lead = Lead::factory()->create();
        $html = '<script>alert(1)</script> <b>urgente</b>';

        $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => $html])->assertCreated()->assertJsonPath('data.body', $html);
        $this->assertSame('application/json', $this->adminGet("/leads/{$lead->public_id}")->headers->get('Content-Type'));
    }

    public function test_rechaza_notas_vacias_largas_o_no_textuales(): void
    {
        $lead = Lead::factory()->create();

        foreach (['', "   \n\t  ", str_repeat('a', 2001), ['texto']] as $body) {
            $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => $body])->assertUnprocessable()->assertJsonValidationErrors(['body']);
        }
        $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => str_repeat('a', 2000)])->assertCreated();

        $this->assertDatabaseCount('lead_notes', 1);
    }

    public function test_una_solicitud_anonimizada_no_admite_notas(): void
    {
        $lead = Lead::factory()->anonymized()->create();

        $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => 'Nota'])
            ->assertStatus(409)->assertJson(['code' => 'lead_anonymized']);
        $this->assertDatabaseCount('lead_notes', 0);
    }
}
