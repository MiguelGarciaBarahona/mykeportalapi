<?php

namespace Tests\Feature\Admin\Leads;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/** Acceso al API de solicitudes: sesión, 2FA, roles, dominio, CSRF, límites y formato de errores. */
class LeadSecurityTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    /** @return array<string, array{string, string, array<string, mixed>}> */
    public static function endpoints(): array
    {
        return [
            'dashboard' => ['GET', '/dashboard', []],
            'listado' => ['GET', '/leads', []],
            'opciones' => ['GET', '/leads/options', []],
            'detalle' => ['GET', '/leads/{lead}', []],
            'estado' => ['PATCH', '/leads/{lead}/status', ['status' => 'contactado']],
            'nota' => ['POST', '/leads/{lead}/notes', ['body' => 'Nota de prueba']],
        ];
    }

    /** @param array<string, mixed> $data */
    private function callAdminEndpoint(string $method, string $path, array $data = [], ?Lead $lead = null)
    {
        $lead ??= Lead::factory()->create();
        $url = $this->adminUrl(str_replace('{lead}', $lead->public_id, $path));

        return $this->withHeaders($this->adminHeaders())->json($method, $url, $data);
    }

    #[DataProvider('endpoints')]
    public function test_sin_sesion_responde_401(string $method, string $path, array $data): void
    {
        $this->callAdminEndpoint($method, $path, $data)->assertUnauthorized()->assertJson(['ok' => false, 'code' => 'unauthenticated']);
        $this->assertDatabaseCount('lead_notes', 0);
        $this->assertDatabaseCount('lead_status_changes', 0);
    }

    #[DataProvider('endpoints')]
    public function test_sin_2fa_confirmado_responde_403(string $method, string $path, array $data): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->callAdminEndpoint($method, $path, $data)->assertForbidden()->assertJson(['code' => 'two_factor_setup_required']);
    }

    #[DataProvider('endpoints')]
    public function test_una_cuenta_desactivada_es_expulsada(string $method, string $path, array $data): void
    {
        $this->actingAs(User::factory()->owner()->withTwoFactor()->inactive()->create());

        $this->callAdminEndpoint($method, $path, $data)->assertForbidden()->assertJson(['code' => 'account_disabled']);
        $this->assertDatabaseCount('lead_notes', 0);
    }

    #[DataProvider('endpoints')]
    public function test_owner_y_admin_pueden_gestionar_solicitudes(string $method, string $path, array $data): void
    {
        foreach ([User::factory()->owner()->withTwoFactor()->create(), User::factory()->withTwoFactor()->create()] as $user) {
            $this->actingAs($user);
            $this->callAdminEndpoint($method, $path, $data)->assertSuccessful();
        }
    }

    public function test_sin_sesion_no_revela_si_una_solicitud_existe(): void
    {
        $lead = Lead::factory()->create();

        $this->adminGet("/leads/{$lead->public_id}")->assertUnauthorized();
        $this->adminGet('/leads/MS-ZZZZZZ')->assertUnauthorized();
    }

    public function test_el_api_no_existe_en_el_dominio_publico(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->getJson('http://mykesystems.test/api/admin/leads')->assertNotFound();
        $this->getJson('http://mykesystems.test/api/admin/dashboard')->assertNotFound();
    }

    public function test_un_token_bearer_no_da_acceso(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer 1|token-inventado'])->adminGet('/leads')->assertUnauthorized();
    }

    public function test_las_escrituras_exigen_token_csrf(): void
    {
        $this->app['env'] = 'local'; // activa la verificación CSRF
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $lead = Lead::factory()->create();

        $this->adminPatch("/leads/{$lead->public_id}/status", ['status' => 'contactado'])
            ->assertStatus(419)->assertJson(['ok' => false, 'code' => 'csrf_token_mismatch']);
        $this->withHeaders(['Sec-Fetch-Site' => 'same-site'])->adminPost("/leads/{$lead->public_id}/notes", ['body' => 'x'])->assertStatus(419);
        $this->assertDatabaseCount('lead_status_changes', 0);
        $this->assertDatabaseCount('lead_notes', 0);

        $this->withSession(['_token' => 'token-de-prueba'])->withHeaders(['X-CSRF-TOKEN' => 'token-de-prueba'])
            ->adminPatch("/leads/{$lead->public_id}/status", ['status' => 'contactado'])->assertOk();
    }

    public function test_limita_las_escrituras_por_administrador(): void
    {
        config(['mykesystems.admin_api.writes_per_minute' => 2]);
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $lead = Lead::factory()->create();

        $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => 'Uno'])->assertCreated();
        $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => 'Dos'])->assertCreated();
        $this->adminPost("/leads/{$lead->public_id}/notes", ['body' => 'Tres'])
            ->assertTooManyRequests()->assertJson(['ok' => false, 'code' => 'too_many_requests'])->assertHeader('Retry-After');

        $this->assertDatabaseCount('lead_notes', 2);
        $this->adminGet('/leads')->assertOk(); // las lecturas tienen su propio límite
    }

    public function test_los_errores_no_exponen_detalles_internos(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());

        foreach ([
            $this->adminGet('/leads/MS-ZZZZZZ')->assertNotFound(),
            $this->adminGet('/ruta-inexistente')->assertNotFound(),
            $this->withHeaders($this->adminHeaders())->deleteJson($this->adminUrl('/leads'))->assertStatus(405),
        ] as $response) {
            $this->assertSame(['ok', 'code', 'message'], array_keys($response->json()));
            foreach (['exception', 'trace', 'file', 'App\\', 'Illuminate\\', 'No query results'] as $leak) {
                $this->assertStringNotContainsString($leak, $response->getContent());
            }
        }
    }

    public function test_opciones_de_filtros(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());

        $data = $this->adminGet('/leads/options')->assertOk()->json('data');

        $this->assertSame(['nueva', 'contactado', 'en_negociacion', 'aceptada', 'cerrada'], array_column($data['statuses'], 'value'));
        $this->assertSame(['contactado', 'en_negociacion'], $data['status_groups'][0]['statuses']);
        $this->assertSame(array_keys(config('mykesystems.services')), array_column($data['services'], 'value'));
    }
}
