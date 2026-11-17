<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * Roles (owner / admin), políticas y acceso al API del panel.
 */
class AuthorizationTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    public function test_sin_sesion_el_api_del_panel_responde_401(): void
    {
        $this->adminGet('/me')->assertUnauthorized();
        $this->adminGet('/admins')->assertUnauthorized();
    }

    public function test_un_token_bearer_no_da_acceso(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer 1|token-inventado'])
            ->adminGet('/me')
            ->assertUnauthorized();
    }

    public function test_solo_el_owner_ve_la_lista_de_administradores(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $admin = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)->adminGet('/admins')->assertForbidden();

        $response = $this->actingAs($owner)->adminGet('/admins')->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->assertArrayNotHasKey('two_factor_secret', $response->json('data.0'));
    }

    public function test_el_owner_puede_desactivar_y_reactivar_a_un_admin(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $admin = User::factory()->withTwoFactor()->create();

        $this->actingAs($owner)->adminPatch("/admins/{$admin->id}/deactivate")->assertOk();
        $this->assertFalse($admin->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.deactivated', 'user_id' => $owner->id, 'subject_id' => (string) $admin->id]);

        $this->actingAs($owner)->adminPatch("/admins/{$admin->id}/activate")->assertOk();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_un_admin_no_puede_desactivar_cuentas(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $other = User::factory()->withTwoFactor()->create();

        $this->actingAs($admin)->adminPatch("/admins/{$other->id}/deactivate")->assertForbidden();
        $this->assertTrue($other->fresh()->is_active);
    }

    public function test_nadie_puede_desactivarse_a_si_mismo(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();

        $this->actingAs($owner)->adminPatch("/admins/{$owner->id}/deactivate")->assertForbidden();
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_un_owner_puede_desactivar_a_otro_owner_si_queda_alguno_activo(): void
    {
        $ownerA = User::factory()->owner()->withTwoFactor()->create();
        $ownerB = User::factory()->owner()->withTwoFactor()->create();

        $this->actingAs($ownerA)->adminPatch("/admins/{$ownerB->id}/deactivate")->assertOk();
        $this->assertFalse(Gate::forUser($ownerA)->allows('deactivate', $ownerA), 'No puede quedarse el panel sin owner');
    }

    public function test_politica_de_solicitudes_por_rol(): void
    {
        $owner = User::factory()->owner()->create();
        $admin = User::factory()->create();
        $inactive = User::factory()->owner()->inactive()->create();
        $lead = Lead::factory()->create();

        foreach (['view', 'update', 'addNote'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $lead), "owner: {$ability}");
            $this->assertTrue(Gate::forUser($admin)->allows($ability, $lead), "admin: {$ability}");
            $this->assertFalse(Gate::forUser($inactive)->allows($ability, $lead), "inactivo: {$ability}");
        }

        foreach (['delete', 'anonymize'] as $ability) {
            $this->assertTrue(Gate::forUser($owner)->allows($ability, $lead), "owner: {$ability}");
            $this->assertFalse(Gate::forUser($admin)->allows($ability, $lead), "admin: {$ability}");
        }

        $this->assertTrue(Gate::forUser($owner)->allows('export', Lead::class));
        $this->assertFalse(Gate::forUser($admin)->allows('export', Lead::class));
        $this->assertFalse(Gate::forUser($inactive)->allows('viewAny', Lead::class));
    }

    public function test_desactivar_registra_auditoria_sin_datos_personales(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $admin = User::factory()->withTwoFactor()->create(['email' => 'privado@mykesystems.test']);

        $this->actingAs($owner)->adminPatch("/admins/{$admin->id}/deactivate")->assertOk();

        $this->assertStringNotContainsString('privado@mykesystems.test', json_encode(AuditLog::all()->map->getAttributes()));
    }
}
