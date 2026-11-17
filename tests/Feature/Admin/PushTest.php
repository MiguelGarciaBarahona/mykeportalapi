<?php

namespace Tests\Feature\Admin;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushNotifier;
use App\Push\WebPushCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * Notificaciones push del panel: suscripciones, envío y avisos automáticos.
 * Ninguna petición sale a internet: el servicio de push se simula con Http::fake().
 */
class PushTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/prueba-123';

    protected function setUp(): void
    {
        parent::setUp();
        $keys = WebPushCrypto::createVapidKeys();
        config([
            'mykesystems.push.public_key' => $keys['publicKey'],
            'mykesystems.push.private_key' => $keys['privateKey'],
            'mykesystems.push.subject' => 'https://admin.mykesystems.test',
        ]);
    }

    /** Datos de suscripción como los entrega el navegador. */
    private function browserSubscription(string $endpoint = self::ENDPOINT, array $preferences = []): array
    {
        [$public] = WebPushCrypto::newKeyPair();

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => WebPushCrypto::b64urlEncode($public), 'auth' => WebPushCrypto::b64urlEncode(random_bytes(16))],
            'preferences' => $preferences,
            'device' => 'Chrome · Windows',
        ];
    }

    private function adminPut(string $path, array $data): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->putJson($this->adminUrl($path), $data);
    }

    private function adminDeleteJson(string $path, array $data): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->deleteJson($this->adminUrl($path), $data);
    }

    private function subscribe(User $user, string $endpoint = self::ENDPOINT, array $preferences = []): void
    {
        $this->actingAs($user)->adminPut('/push/subscription', $this->browserSubscription($endpoint, $preferences))->assertSuccessful();
    }

    public function test_sin_sesion_responde_401(): void
    {
        $this->adminGet('/push')->assertUnauthorized();
    }

    public function test_config_indica_si_hay_claves(): void
    {
        $admin = User::factory()->withTwoFactor()->create();
        $this->actingAs($admin)->adminGet('/push')->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.public_key', config('mykesystems.push.public_key'))
            ->assertJsonPath('data.can_month_end', false);

        config(['mykesystems.push.private_key' => null]);
        $this->adminGet('/push')->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.public_key', null);
        $this->adminPut('/push/subscription', $this->browserSubscription())->assertStatus(503);
    }

    public function test_suscribir_consultar_y_desactivar(): void
    {
        $owner = User::factory()->owner()->withTwoFactor()->create();
        $this->actingAs($owner);

        $this->adminPut('/push/subscription', $this->browserSubscription(preferences: ['stale_leads' => false]))
            ->assertCreated()
            ->assertJsonPath('data.preferences', ['new_leads' => true, 'stale_leads' => false, 'month_end' => true]);

        $this->adminPost('/push/subscription/status', ['endpoint' => self::ENDPOINT])->assertOk()->assertJsonPath('data.subscribed', true);
        $this->adminPost('/push/subscription/status', ['endpoint' => self::ENDPOINT.'-otro'])->assertOk()->assertJsonPath('data.subscribed', false);

        // Volver a suscribir el mismo navegador actualiza (no duplica)
        $this->adminPut('/push/subscription', $this->browserSubscription(preferences: ['new_leads' => false]))->assertOk();
        $this->assertSame(1, PushSubscription::count());

        $response = $this->adminGet('/push')->assertOk();
        $this->assertSame('Chrome · Windows', $response->json('data.devices.0.device'));
        $this->assertStringNotContainsString('prueba-123', $response->getContent());

        $this->adminDeleteJson('/push/subscription', ['endpoint' => self::ENDPOINT])->assertOk()->assertJsonPath('data.subscribed', false);
        $this->assertSame(0, PushSubscription::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'push.unsubscribed', 'user_id' => $owner->id]);
    }

    public function test_un_admin_no_puede_activar_el_recordatorio_de_finanzas(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $this->adminPut('/push/subscription', $this->browserSubscription(preferences: ['month_end' => true]))
            ->assertCreated()->assertJsonPath('data.preferences.month_end', false);
    }

    public function test_rechaza_servicios_de_push_desconocidos_y_claves_invalidas(): void
    {
        $this->actingAs(User::factory()->withTwoFactor()->create());
        foreach (['https://evil.example.com/push', 'http://fcm.googleapis.com/x', 'https://fcm.googleapis.com.evil.com/x', 'https://user:pw@fcm.googleapis.com/x'] as $endpoint) {
            $this->adminPut('/push/subscription', $this->browserSubscription($endpoint))->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        }
        $bad = $this->browserSubscription();
        $bad['keys']['p256dh'] = WebPushCrypto::b64urlEncode(random_bytes(65));
        $this->adminPut('/push/subscription', $bad)->assertUnprocessable();
        $this->assertSame(0, PushSubscription::count());
        $this->assertTrue(PushNotifier::isAllowedEndpoint('https://web.push.apple.com/abc'));
        $this->assertTrue(PushNotifier::isAllowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/abc'));
    }

    public function test_notificacion_de_prueba_cifrada_y_firmada(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $admin = User::factory()->withTwoFactor()->create();
        $this->subscribe($admin);

        $this->adminPost('/push/test', ['endpoint' => self::ENDPOINT])->assertOk()->assertJsonPath('data.sent', 1);

        Http::assertSent(function (HttpRequest $request) {
            return $request->url() === self::ENDPOINT
                && $request->hasHeader('Content-Encoding', 'aes128gcm')
                && str_starts_with($request->header('Authorization')[0], 'vapid t=')
                && str_contains($request->header('Authorization')[0], 'k='.config('mykesystems.push.public_key'))
                && strlen($request->body()) > 86
                && ! str_contains($request->body(), 'notificaciones'); // el contenido viaja cifrado
        });
        $this->assertNotNull(PushSubscription::first()->last_success_at);
    }

    public function test_suscripciones_vencidas_se_eliminan(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 410)]);
        $admin = User::factory()->withTwoFactor()->create();
        $this->subscribe($admin);

        $this->adminPost('/push/test', [])->assertOk()->assertJsonPath('data.removed', 1);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_nueva_solicitud_avisa_sin_datos_personales(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $admin = User::factory()->withTwoFactor()->create();
        $this->subscribe($admin);
        $this->subscribe(User::factory()->withTwoFactor()->create(), self::ENDPOINT.'-sin-aviso', ['new_leads' => false]);
        // Cuenta desactivada: no recibe (se crea directo porque ya no puede iniciar sesión).
        $inactive = User::factory()->withTwoFactor()->inactive()->create();
        $sub = $this->browserSubscription(self::ENDPOINT.'-inactivo');
        PushSubscription::create(['user_id' => $inactive->id, 'endpoint_hash' => PushSubscription::hashEndpoint($sub['endpoint']), 'endpoint' => $sub['endpoint'], 'public_key' => $sub['keys']['p256dh'], 'auth_token' => $sub['keys']['auth']]);

        config([
            'mykesystems.allowed_origins' => ['https://mykesystems.com'],
            'mykesystems.notifications.admin_email' => null,
        ]);
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Origin' => 'https://mykesystems.com'])->postJson('/api/v1/leads', [
            'first_name' => 'Ana', 'last_name' => 'Prueba', 'phone' => '9999-0000', 'email' => 'ana@ejemplo.com',
            'business' => null, 'service' => 'crm-erp', 'message' => 'Necesito un sistema para gestionar clientes y ventas.',
            'source' => 'prueba', 'started_at' => now()->subSeconds(30)->getTimestampMs(), 'website' => '',
        ])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::ENDPOINT);
    }

    public function test_aviso_de_solicitudes_sin_atender_una_sola_vez(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $this->subscribe(User::factory()->withTwoFactor()->create());

        $old = Lead::factory()->create(['status_changed_at' => now()->subHours(30)]);
        Lead::factory()->create(['status_changed_at' => now()->subHours(2)]);
        Lead::factory()->status(LeadStatus::Contactado)->create(['status_changed_at' => now()->subHours(50)]);

        $this->artisan('leads:notify-stale')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertNotNull($old->fresh()->stale_notified_at);

        $this->artisan('leads:notify-stale')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_recordatorio_de_fin_de_mes_solo_al_propietario(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $this->subscribe(User::factory()->owner()->withTwoFactor()->create(), self::ENDPOINT.'-owner');
        $this->subscribe(User::factory()->withTwoFactor()->create(), self::ENDPOINT.'-admin');

        $this->travelTo(now('America/Tegucigalpa')->setDate(2026, 11, 15)->setTime(17, 0));
        $this->artisan('finance:notify-month-end')->assertSuccessful();
        Http::assertNothingSent();

        $this->artisan('finance:notify-month-end', ['--force' => true])->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::ENDPOINT.'-owner');
    }
}
