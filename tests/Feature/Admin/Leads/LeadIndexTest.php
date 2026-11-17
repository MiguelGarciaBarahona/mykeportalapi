<?php

namespace Tests\Feature\Admin\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/** GET /api/admin/leads — paginación, filtros, búsqueda y orden. */
class LeadIndexTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->withTwoFactor()->create());
    }

    /** @return list<string> */
    private function ids(string $query = ''): array
    {
        return array_column($this->adminGet('/leads'.$query)->assertOk()->json('data'), 'id');
    }

    /* Paginación ------------------------------------------------------- */

    public function test_pagina_los_resultados(): void
    {
        Lead::factory()->count(25)->create();

        $this->adminGet('/leads?per_page=10&page=3')->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJson(['meta' => ['current_page' => 3, 'last_page' => 3, 'per_page' => 10, 'total' => 25, 'from' => 21, 'to' => 25]]);

        $this->adminGet('/leads')->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 20);
    }

    public function test_una_pagina_sin_resultados_devuelve_una_lista_vacia(): void
    {
        Lead::factory()->count(2)->create();

        $this->adminGet('/leads?page=5')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_rechaza_parametros_de_paginacion_invalidos(): void
    {
        $this->adminGet('/leads?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->adminGet('/leads?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->adminGet('/leads?page=0')->assertUnprocessable()->assertJsonValidationErrors(['page']);
        $this->adminGet('/leads?sort=email')->assertUnprocessable()->assertJsonValidationErrors(['sort']);
    }

    /* Orden ------------------------------------------------------------ */

    public function test_ordena_por_fecha_de_recepcion(): void
    {
        $old = Lead::factory()->create(['created_at' => now()->subDays(3)]);
        $new = Lead::factory()->create(['created_at' => now()->subDay()]);
        $mid = Lead::factory()->create(['created_at' => now()->subDays(2)]);

        $this->assertSame([$new->public_id, $mid->public_id, $old->public_id], $this->ids());
        $this->assertSame([$old->public_id, $mid->public_id, $new->public_id], $this->ids('?sort=created_at'));
    }

    /* Filtros ---------------------------------------------------------- */

    public function test_filtra_por_estado(): void
    {
        $nueva = Lead::factory()->create();
        $contactado = Lead::factory()->status(LeadStatus::Contactado)->create();
        $negociacion = Lead::factory()->status(LeadStatus::EnNegociacion)->create();
        $cerrada = Lead::factory()->status(LeadStatus::Cerrada)->create();

        $this->assertSame([$nueva->public_id], $this->ids('?status=nueva'));
        $this->assertEqualsCanonicalizing([$nueva->public_id, $cerrada->public_id], $this->ids('?status=nueva,cerrada'));
        $this->assertEqualsCanonicalizing([$nueva->public_id, $cerrada->public_id], $this->ids('?status[]=nueva&status[]=cerrada'));
        $this->assertEqualsCanonicalizing([$contactado->public_id, $negociacion->public_id], $this->ids('?status=en_proceso'));

        $this->adminGet('/leads?status=borrada')->assertUnprocessable()->assertJsonValidationErrors(['status.0']);
    }

    public function test_filtra_por_servicio(): void
    {
        $crm = Lead::factory()->create(['service' => 'crm-erp']);
        Lead::factory()->create(['service' => 'desarrollo-web']);

        $this->assertSame([$crm->public_id], $this->ids('?service=crm-erp'));
        $this->adminGet('/leads?service=inexistente')->assertUnprocessable()->assertJsonValidationErrors(['service.0']);
    }

    public function test_el_filtro_de_whatsapp_retirado_se_ignora(): void
    {
        $legacy = Lead::factory()->withLegacyWhatsappConsent()->create();
        $other = Lead::factory()->create();

        // Un enlace antiguo con ?whatsapp=… no filtra ni falla.
        $this->assertEqualsCanonicalizing([$legacy->public_id, $other->public_id], $this->ids('?whatsapp=true'));
        $this->assertEqualsCanonicalizing([$legacy->public_id, $other->public_id], $this->ids('?whatsapp=quizas'));
    }

    public function test_filtra_por_fecha_en_la_zona_horaria_del_panel(): void
    {
        config(['mykesystems.admin_api.timezone' => 'America/Tegucigalpa']); // UTC-6
        $nightBefore = Lead::factory()->create(['created_at' => Carbon::parse('2026-11-10 05:00:00', 'UTC')]); // 9 nov, 23:00 local
        $sameDay = Lead::factory()->create(['created_at' => Carbon::parse('2026-11-10 07:00:00', 'UTC')]);     // 10 nov, 01:00 local
        $lastMinute = Lead::factory()->create(['created_at' => Carbon::parse('2026-11-11 05:59:59', 'UTC')]); // 10 nov, 23:59 local
        Lead::factory()->create(['created_at' => Carbon::parse('2026-11-11 06:00:00', 'UTC')]);                 // 11 nov, 00:00 local

        $this->assertEqualsCanonicalizing([$sameDay->public_id, $lastMinute->public_id], $this->ids('?date_from=2026-11-10&date_to=2026-11-10'));
        $this->assertSame([$nightBefore->public_id], $this->ids('?date_to=2026-11-09'));

        $this->adminGet('/leads?date_from=2026-11-10&date_to=2026-11-09')->assertUnprocessable()->assertJsonValidationErrors(['date_to']);
        $this->adminGet('/leads?date_from=10/11/2026')->assertUnprocessable()->assertJsonValidationErrors(['date_from']);
    }

    public function test_combina_filtros(): void
    {
        $match = Lead::factory()->status(LeadStatus::Contactado)->create(['service' => 'automatizacion', 'created_at' => now()->subDay()]);
        Lead::factory()->status(LeadStatus::Contactado)->create(['service' => 'crm-erp']);
        Lead::factory()->create(['service' => 'automatizacion']);

        $this->assertSame([$match->public_id], $this->ids('?status=contactado&service=automatizacion'));
    }

    public function test_no_lista_solicitudes_eliminadas(): void
    {
        $visible = Lead::factory()->create();
        Lead::factory()->create()->delete();

        $this->assertSame([$visible->public_id], $this->ids());
    }

    /* Búsqueda --------------------------------------------------------- */

    public function test_busca_por_nombre_correo_negocio_numero_y_telefono(): void
    {
        $xiomara = Lead::factory()->create([
            'first_name' => 'Xiomara', 'last_name' => 'Quintanilla', 'email' => 'xq@ejemplo.test',
            'business' => 'Panadería Aurora', 'phone' => '+50431234567',
        ]);
        Lead::factory()->create([
            'first_name' => 'Rodrigo', 'last_name' => 'Betancourt', 'email' => 'rb@ejemplo.test',
            'business' => null, 'phone' => '+50498765432',
        ]);

        foreach (['quintan', 'XIOMARA', 'xq@ejemplo', 'aurora', $xiomara->public_id, strtolower($xiomara->public_id), '3123-4567', 'xiomara quintanilla'] as $term) {
            $this->assertSame([$xiomara->public_id], $this->ids('?search='.rawurlencode($term)), "Búsqueda: {$term}");
        }

        $this->assertSame([], $this->ids('?search='.rawurlencode('xiomara betancourt')), 'Todas las palabras deben coincidir');
    }

    public function test_los_comodines_se_buscan_como_texto_literal(): void
    {
        foreach (['ana', 'luis', 'eva'] as $name) {
            Lead::factory()->create(['first_name' => ucfirst($name), 'last_name' => 'Prueba', 'email' => "{$name}@ejemplo.test", 'business' => null]);
        }

        $this->assertSame([], $this->ids('?search=%25'));
        $this->assertSame([], $this->ids('?search=_'));
        $this->adminGet('/leads?search='.str_repeat('a', 101))->assertUnprocessable()->assertJsonValidationErrors(['search']);
    }

    /* Formato ---------------------------------------------------------- */

    public function test_cada_elemento_tiene_solo_los_datos_del_listado(): void
    {
        $lead = Lead::factory()->withLegacyWhatsappConsent()->create(['first_name' => 'Ana', 'last_name' => 'Prueba', 'service' => 'crm-erp']);
        $lead->notes()->create(['user_id' => null, 'body' => 'Nota de prueba']);

        $item = $this->adminGet('/leads')->assertOk()->json('data.0');

        $this->assertSame([
            'id', 'full_name', 'business', 'service', 'status',
            'notes_count', 'anonymized', 'created_at', 'status_changed_at',
        ], array_keys($item));
        $this->assertSame($lead->public_id, $item['id']);
        $this->assertSame('Ana Prueba', $item['full_name']);
        $this->assertSame(['value' => 'crm-erp', 'label' => 'CRM y ERP personalizados'], $item['service']);
        $this->assertSame(['value' => 'nueva', 'label' => 'Nueva'], $item['status']);
        $this->assertSame(1, $item['notes_count']);
    }
}
