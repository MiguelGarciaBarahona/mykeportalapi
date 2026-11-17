<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * Inicio de sesión, segundo factor, sesiones, CSRF y recuperación de contraseña.
 */
class AuthenticationTest extends TestCase
{
    use InteractsWithAdminPanel, RefreshDatabase;

    /* ---------------------------------------------------------------
     | Inicio de sesión
     * ------------------------------------------------------------- */

    public function test_sin_2fa_configurado_solo_permite_ver_su_perfil_y_configurarlo(): void
    {
        $user = User::factory()->owner()->create();

        $this->login($user)->assertOk()->assertJson(['two_factor' => false]);

        $this->adminGet('/me')->assertOk()->assertJson([
            'email' => $user->email,
            'role' => 'owner',
            'two_factor_confirmed' => false,
        ]);

        $this->adminGet('/admins')->assertForbidden()->assertJson(['code' => 'two_factor_setup_required']);
    }

    public function test_con_2fa_confirmado_exige_el_codigo_antes_de_iniciar_sesion(): void
    {
        $user = User::factory()->owner()->withTwoFactor()->create();

        $this->login($user)->assertOk()->assertJson(['two_factor' => true]);
        $this->adminGet('/me')->assertUnauthorized();

        $this->adminPost('/auth/two-factor-challenge', ['code' => $this->totp($user)])->assertNoContent();

        $this->adminGet('/me')->assertOk()->assertJson(['two_factor_confirmed' => true]);
        $this->adminGet('/admins')->assertOk();
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_un_codigo_2fa_incorrecto_no_inicia_sesion(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->login($user)->assertJson(['two_factor' => true]);
        $this->adminPost('/auth/two-factor-challenge', ['code' => '000000'])->assertUnprocessable();

        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_un_codigo_de_recuperacion_solo_sirve_una_vez(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $this->login($user);
        $this->adminPost('/auth/two-factor-challenge', ['recovery_code' => 'codigo-recuperacion-1'])->assertNoContent();
        $this->adminPost('/auth/logout')->assertNoContent();

        $this->login($user);
        $this->adminPost('/auth/two-factor-challenge', ['recovery_code' => 'codigo-recuperacion-1'])->assertUnprocessable();
        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_las_credenciales_invalidas_dan_un_error_generico(): void
    {
        $user = User::factory()->create();

        $wrongPassword = $this->login($user, 'Contraseña-Incorrecta-1!')->assertUnprocessable();
        $unknownEmail = $this->adminPost('/auth/login', [
            'email' => 'no-existe@mykesystems.test',
            'password' => 'Contraseña-Incorrecta-1!',
        ])->assertUnprocessable();

        $this->assertSame($wrongPassword->json('errors'), $unknownEmail->json('errors'));
        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_una_cuenta_desactivada_no_puede_iniciar_sesion(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->inactive()->create();

        $wrong = $this->login($active, 'Contraseña-Incorrecta-1!')->assertUnprocessable();
        $blocked = $this->login($inactive)->assertUnprocessable(); // contraseña correcta

        // Mismo mensaje: no revela que la cuenta existe pero está desactivada.
        $this->assertSame($wrong->json('errors'), $blocked->json('errors'));
        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_limita_los_intentos_de_inicio_de_sesion(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->login($user, 'Contraseña-Incorrecta-1!')->assertUnprocessable();
        }

        // Ni siquiera la contraseña correcta funciona mientras dure el bloqueo.
        $this->login($user)->assertTooManyRequests();
        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_el_cierre_de_sesion_invalida_la_sesion(): void
    {
        $user = User::factory()->owner()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);

        // Ambas rutas resuelven (y memorizan) al usuario en el guard sanctum antes del logout.
        $this->adminGet('/me')->assertOk();
        $this->adminGet('/admins')->assertOk();
        $sessionId = $this->app['session.store']->getId();

        $this->adminPost('/auth/logout')->assertNoContent();

        $this->assertNotSame($sessionId, $this->app['session.store']->getId(), 'La sesión debe invalidarse');
        $this->assertFalse($this->app['session.store']->has(Auth::guard('web')->getName()));
        $this->assertGuest('web');
        $this->assertGuest('sanctum');

        $this->adminGet('/me')->assertUnauthorized();
        $this->adminGet('/admins')->assertUnauthorized();
        $this->adminPatch("/admins/{$user->id}/deactivate")->assertUnauthorized();
    }

    public function test_el_cierre_de_sesion_regenera_el_token_csrf(): void
    {
        $this->app['env'] = 'local'; // verificación CSRF activa
        $user = User::factory()->withTwoFactor()->create();
        $csrf = fn () => ['X-CSRF-TOKEN' => $this->app['session.store']->token()];

        $this->withSession(['_token' => 'token-inicial']);
        $this->withHeaders($csrf())->login($user)->assertOk()->assertJson(['two_factor' => true]);
        $this->withHeaders($csrf())->adminPost('/auth/two-factor-challenge', ['code' => $this->totp($user)])->assertNoContent();
        $this->adminGet('/me')->assertOk();

        $tokenBeforeLogout = $this->app['session.store']->token();
        $this->withHeaders($csrf())->adminPost('/auth/logout')->assertNoContent();

        $this->assertNotSame($tokenBeforeLogout, $this->app['session.store']->token(), 'El token CSRF debe regenerarse');
        $this->adminGet('/me')->assertUnauthorized();

        // El token anterior ya no sirve para ninguna petición que modifique estado.
        $this->withHeaders(['X-CSRF-TOKEN' => $tokenBeforeLogout])->login($user)->assertStatus(419);
    }

    public function test_tras_el_logout_un_nuevo_inicio_exige_otra_vez_el_2fa(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);
        $this->adminGet('/me')->assertOk();
        $this->adminPost('/auth/logout')->assertNoContent();

        $this->login($user)->assertOk()->assertJson(['two_factor' => true]);
        $this->adminGet('/me')->assertUnauthorized();
    }

    /* ---------------------------------------------------------------
     | Rutas que no deben existir
     * ------------------------------------------------------------- */

    public function test_no_existe_registro_publico(): void
    {
        $data = ['name' => 'Intruso', 'email' => 'x@x.test', 'password' => 'Clave-Segura-2026!', 'password_confirmation' => 'Clave-Segura-2026!'];

        $this->adminPost('/auth/register', $data)->assertNotFound();
        $this->withHeaders($this->adminHeaders())->postJson($this->adminHost.'/register', $data)->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_no_se_puede_desactivar_el_2fa(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson($this->adminUrl('/auth/user/two-factor-authentication'))
            ->assertStatus(405);

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
    }

    public function test_las_rutas_del_panel_no_existen_en_el_dominio_publico(): void
    {
        $user = User::factory()->create();

        $this->postJson('http://mykesystems.test/api/admin/auth/login', [
            'email' => $user->email, 'password' => UserFactory::PASSWORD,
        ])->assertNotFound();
        $this->getJson('http://mykesystems.test/api/admin/me')->assertNotFound();
    }

    /* ---------------------------------------------------------------
     | Configuración del 2FA
     * ------------------------------------------------------------- */

    public function test_configuracion_completa_del_2fa(): void
    {
        $user = User::factory()->owner()->create();
        $this->login($user)->assertJson(['two_factor' => false]);

        // Gestionar el 2FA exige confirmar la contraseña.
        $this->adminPost('/auth/user/two-factor-authentication')->assertStatus(423);
        $this->adminPost('/auth/user/confirm-password', ['password' => UserFactory::PASSWORD])->assertCreated();

        $this->adminPost('/auth/user/two-factor-authentication')->assertSuccessful();
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at, 'Aún no está confirmado');
        $this->adminGet('/admins')->assertForbidden();

        $this->adminGet('/auth/user/two-factor-qr-code')->assertOk()->assertJsonStructure(['svg']);

        $this->adminPost('/auth/user/confirmed-two-factor-authentication', ['code' => '000000'])->assertUnprocessable();
        $this->adminPost('/auth/user/confirmed-two-factor-authentication', ['code' => $this->totp($user)])->assertSuccessful();

        $this->assertTrue($user->fresh()->hasConfirmedTwoFactor());
        $this->adminGet('/me')->assertJson(['two_factor_confirmed' => true]);
        $this->adminGet('/admins')->assertOk();
        $this->assertCount(8, $this->adminGet('/auth/user/two-factor-recovery-codes')->assertOk()->json());
    }

    public function test_el_secreto_2fa_nunca_se_expone_al_serializar(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $json = $user->toJson();

        $this->assertStringNotContainsString('two_factor_secret', $json);
        $this->assertStringNotContainsString('two_factor_recovery_codes', $json);
        $this->assertStringNotContainsString('password', $json);
    }

    /* ---------------------------------------------------------------
     | Sesiones
     * ------------------------------------------------------------- */

    public function test_una_cuenta_desactivada_es_expulsada_en_su_siguiente_peticion(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);
        $this->adminGet('/me')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->adminGet('/me')->assertForbidden()->assertJson(['code' => 'account_disabled']);
        $this->adminGet('/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.blocked_inactive', 'user_id' => $user->id]);
    }

    public function test_una_desactivacion_hecha_desde_otro_proceso_tambien_expulsa(): void
    {
        $user = User::factory()->owner()->withTwoFactor()->create();
        $other = User::factory()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);
        $this->adminGet('/admins')->assertOk(); // el guard ya tiene al usuario en memoria

        // Cambio directo en la base de datos: la instancia en memoria sigue diciendo is_active = true.
        User::query()->whereKey($user->id)->update(['is_active' => false]);

        $this->adminGet('/admins')->assertForbidden()->assertJson(['code' => 'account_disabled']);
        $this->assertGuest('web');
        $this->adminGet('/me')->assertUnauthorized();
        $this->assertSame(1, AuditLog::where('action', 'auth.blocked_inactive')->where('user_id', $user->id)->count());
        $this->assertTrue($other->fresh()->is_active, 'No afecta a otras cuentas');
    }

    public function test_una_cuenta_activa_sigue_autorizada_tras_leer_su_estado_vigente(): void
    {
        // Regresión: el estado vigente se lee de la base de datos en cada petición; una
        // consulta incorrecta (p. ej. una columna inexistente) haría que una cuenta activa
        // pareciera desactivada.
        $user = User::factory()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);

        $this->adminGet('/me')->assertOk()->assertJson(['role' => 'admin', 'permissions' => ['manage_admins' => false]]);
        $this->adminGet('/me')->assertOk(); // peticiones repetidas
        $this->adminGet('/admins')->assertForbidden()->assertJsonMissing(['code' => 'account_disabled']);

        // Un cambio de rol hecho desde otro proceso se aplica en la siguiente petición.
        User::query()->whereKey($user->id)->update(['role' => 'owner']);

        $this->adminGet('/me')->assertOk()->assertJson(['role' => 'owner', 'permissions' => ['manage_admins' => true]]);
        $this->adminGet('/admins')->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.blocked_inactive']);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_un_admin_activo_sin_2fa_puede_ver_su_perfil_y_empezar_la_configuracion(): void
    {
        $user = User::factory()->create(); // rol admin, activo, sin 2FA
        $this->login($user)->assertOk()->assertJson(['two_factor' => false]);

        $this->adminGet('/me')->assertOk()->assertJson(['two_factor_confirmed' => false]);
        $this->adminPost('/auth/user/confirm-password', ['password' => UserFactory::PASSWORD])->assertCreated();
        $this->adminPost('/auth/user/two-factor-authentication')->assertSuccessful();
        $this->adminGet('/auth/user/two-factor-qr-code')->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'auth.blocked_inactive']);
    }

    public function test_la_sesion_caduca_tras_la_duracion_maxima(): void
    {
        config(['mykesystems.admin_session.absolute_lifetime_hours' => 12]);
        $user = User::factory()->withTwoFactor()->create();
        $this->loginWithTwoFactor($user);
        $this->adminGet('/me')->assertOk();

        $this->travel(13)->hours();

        $this->adminGet('/me')->assertUnauthorized()->assertJson(['code' => 'session_expired']);
        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_la_cookie_de_sesion_es_segura_y_solo_del_panel(): void
    {
        $user = User::factory()->create();
        $response = $this->login($user)->assertOk();

        $session = $response->getCookie(config('session.cookie'), decrypt: false);
        $this->assertNotNull($session, 'Debe emitirse la cookie de sesión');
        $this->assertTrue($session->isHttpOnly(), 'La sesión no debe ser accesible desde JavaScript');
        $this->assertSame('strict', strtolower((string) $session->getSameSite()));
        $this->assertNull($session->getDomain(), 'Sin atributo Domain: la cookie no se comparte con mykesystems.com');

        $xsrf = $response->getCookie('XSRF-TOKEN', decrypt: false);
        $this->assertNotNull($xsrf);
        $this->assertFalse($xsrf->isHttpOnly(), 'La SPA debe poder leer el token CSRF');
        $this->assertNull($xsrf->getDomain());
    }

    public function test_el_endpoint_publico_no_crea_sesion_ni_cookies(): void
    {
        config([
            'mykesystems.allowed_origins' => ['https://mykesystems.com'],
            'mykesystems.notifications.admin_email' => null,
        ]);

        $response = $this->withHeaders(['Origin' => 'https://mykesystems.com'])->postJson('/api/v1/leads', [
            'first_name' => 'Ana', 'last_name' => 'Prueba', 'phone' => '9999-0000',
            'email' => 'ana@ejemplo.com', 'service' => 'crm-erp',
            'message' => 'Necesito un sistema para gestionar clientes y ventas.',
            'whatsapp_consent' => false, 'started_at' => now()->subSeconds(30)->getTimestampMs(), 'website' => '',
        ])->assertCreated();

        $this->assertSame([], $response->headers->getCookies());
    }

    /* ---------------------------------------------------------------
     | CSRF (en pruebas Laravel la omite: se reactiva para estas pruebas)
     * ------------------------------------------------------------- */

    public function test_las_peticiones_sin_token_csrf_son_rechazadas(): void
    {
        $this->app['env'] = 'local'; // activa la verificación CSRF dentro de la prueba
        $user = User::factory()->create();

        $this->login($user)->assertStatus(419);

        // Petición desde otro subdominio del mismo sitio (p. ej. mykesystems.com): también rechazada.
        $this->withHeaders(['Sec-Fetch-Site' => 'same-site'])->login($user)->assertStatus(419);

        $this->adminGet('/me')->assertUnauthorized();
    }

    public function test_las_peticiones_con_token_csrf_valido_se_aceptan(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create();

        $this->withSession(['_token' => 'token-de-prueba'])
            ->withHeaders(['X-CSRF-TOKEN' => 'token-de-prueba'])
            ->login($user)
            ->assertOk();
    }

    /* ---------------------------------------------------------------
     | Recuperación de contraseña
     * ------------------------------------------------------------- */

    public function test_la_recuperacion_no_revela_si_el_correo_existe(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $existing = $this->adminPost('/auth/forgot-password', ['email' => $user->email])->assertOk();
        $missing = $this->adminPost('/auth/forgot-password', ['email' => 'no-existe@mykesystems.test'])->assertOk();

        $this->assertSame($existing->json(), $missing->json());
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use ($user) {
            $url = $n->toMail($user)->actionUrl;

            return str_starts_with($url, config('mykesystems.notifications.admin_panel_url').'/restablecer-contrasena?token=');
        });
        Notification::assertCount(1);
    }

    public function test_restablece_la_contrasena_con_un_token_valido(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->adminPost('/auth/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $newPassword = 'Nueva-Clave-Segura-2026!';
        $this->adminPost('/auth/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => $newPassword, 'password_confirmation' => $newPassword,
        ])->assertOk();

        $this->login($user, UserFactory::PASSWORD)->assertUnprocessable();
        $this->login($user, $newPassword)->assertOk();
    }

    public function test_el_restablecimiento_exige_una_contrasena_robusta(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->adminPost('/auth/forgot-password', ['email' => $user->email]);

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        // Dos intentos: el límite de recuperación es de 3 peticiones por minuto (1 solicitud + 2 intentos).
        foreach (['corta', 'SoloLetrasLargasSinNumeros'] as $weak) {
            $this->adminPost('/auth/reset-password', [
                'token' => $token, 'email' => $user->email,
                'password' => $weak, 'password_confirmation' => $weak,
            ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
        }
    }

    /* ---------------------------------------------------------------
     | Auditoría
     * ------------------------------------------------------------- */

    public function test_la_auditoria_registra_eventos_sin_datos_sensibles(): void
    {
        $user = User::factory()->withTwoFactor()->create(['email' => 'auditoria@mykesystems.test']);

        $this->login($user, 'Contraseña-Incorrecta-1!');
        $code = $this->totp($user);
        $this->login($user);
        $this->adminPost('/auth/two-factor-challenge', ['code' => $code])->assertNoContent();
        $this->adminPost('/auth/logout');

        $actions = AuditLog::pluck('action')->all();
        foreach (['auth.login_failed', '2fa.challenged', 'auth.login', 'auth.logout'] as $expected) {
            $this->assertContains($expected, $actions);
        }

        $dump = json_encode(AuditLog::all()->map->getAttributes());
        foreach (['auditoria@mykesystems.test', UserFactory::PASSWORD, 'Contraseña-Incorrecta-1!', $code, '127.0.0.1'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }
}
