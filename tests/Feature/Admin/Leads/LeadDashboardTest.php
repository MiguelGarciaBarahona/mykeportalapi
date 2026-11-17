<?php

namespace Tests\Feature\Admin\Leads;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/** GET /api/admin/dashboard — estadísticas reales a partir de la base de datos. */
class LeadDashboardTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    public function test_sin_solicitudes_todas_las_cifras_son_cero(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $response = $this->adminGet('/dashboard')->assertOk()->assertJson(['data' => [
            'totals' => ['total' => 0, 'nuevas' => 0, 'en_proceso' => 0, 'aceptadas' => 0, 'cerradas' => 0],
            'received' => ['last_7_days' => 0, 'last_30_days' => 0],
        ]]);

        $this->assertCount(count(LeadStatus::cases()), $response->json('data.by_status'));
        $this->assertSame([0], array_values(array_unique(array_column($response->json('data.by_status'), 'count'))));
    }

    public function test_cuenta_las_solicitudes_por_estado_y_periodo(): void
    {
        Lead::factory()->count(3)->create();
        Lead::factory()->create(['created_at' => now()->subDays(40)]);
        Lead::factory()->count(2)->status(LeadStatus::Contactado)->withLegacyWhatsappConsent()->create();
        Lead::factory()->status(LeadStatus::EnNegociacion)->create();
        Lead::factory()->count(2)->status(LeadStatus::Aceptada)->create();
        Lead::factory()->status(LeadStatus::Cerrada)->create(['created_at' => now()->subDays(10)]);
        Lead::factory()->withLegacyWhatsappConsent()->create()->delete(); // borrado lógico: no cuenta

        $this->actingAs(User::factory()->withTwoFactor()->create()); // rol admin

        $response = $this->adminGet('/dashboard')->assertOk()->assertJson(['data' => [
            'totals' => ['total' => 10, 'nuevas' => 4, 'en_proceso' => 3, 'aceptadas' => 2, 'cerradas' => 1],
            'received' => ['last_7_days' => 8, 'last_30_days' => 9],
        ]]);

        $this->assertArrayNotHasKey('with_whatsapp_consent', $response->json('data'), 'El dashboard ya no informa sobre WhatsApp');

        $byStatus = collect($response->json('data.by_status'))->keyBy('value');
        $this->assertSame(['value' => 'en_negociacion', 'label' => 'En negociación', 'count' => 1], $byStatus['en_negociacion']);
        $this->assertSame(2, $byStatus['contactado']['count']);
        $this->assertNotNull($response->json('data.generated_at'));
    }
}
