<?php

namespace Tests\Feature\Admin;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Comandos de consola: admin:create y admin:deactivate.
 */
class AdminCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Clave-Muy-Segura-2026!';

    public function test_el_primer_administrador_se_crea_como_owner(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ana Administradora', '--email' => 'Ana@MykeSystems.test'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos)', self::STRONG)
            ->expectsQuestion('Repite la contraseña', self::STRONG)
            ->assertSuccessful();

        $user = User::firstOrFail();
        $this->assertSame('ana@mykesystems.test', $user->email);
        $this->assertSame(AdminRole::Owner, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertNotSame(self::STRONG, $user->password, 'La contraseña nunca se guarda en claro');
        $this->assertTrue(Hash::check(self::STRONG, $user->password));
        $this->assertNull($user->two_factor_confirmed_at, 'El 2FA se configura en el primer inicio de sesión');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.created', 'subject_id' => (string) $user->id]);
    }

    public function test_los_siguientes_administradores_eligen_rol(): void
    {
        User::factory()->owner()->create();

        $this->artisan('admin:create', ['--name' => 'Luis Admin', '--email' => 'luis@mykesystems.test'])
            ->expectsChoice('Rol', 'admin', ['admin', 'owner'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos)', self::STRONG)
            ->expectsQuestion('Repite la contraseña', self::STRONG)
            ->assertSuccessful();

        $this->assertSame(AdminRole::Admin, User::where('email', 'luis@mykesystems.test')->firstOrFail()->role);
    }

    public function test_rechaza_contrasenas_debiles(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ana', '--email' => 'ana@mykesystems.test'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rechaza_contrasenas_que_no_coinciden(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ana', '--email' => 'ana@mykesystems.test'])
            ->expectsQuestion('Contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos)', self::STRONG)
            ->expectsQuestion('Repite la contraseña', self::STRONG.'x')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rechaza_correos_duplicados_o_invalidos(): void
    {
        User::factory()->owner()->create(['email' => 'ana@mykesystems.test']);

        foreach (['ana@mykesystems.test', 'no-es-correo'] as $email) {
            $this->artisan('admin:create', ['--name' => 'Ana', '--email' => $email, '--role' => 'admin'])
                ->expectsQuestion('Contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos)', self::STRONG)
                ->expectsQuestion('Repite la contraseña', self::STRONG)
                ->assertFailed();
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_no_funciona_sin_interaccion(): void
    {
        $this->artisan('admin:create', ['--name' => 'Ana', '--email' => 'ana@mykesystems.test', '--no-interaction' => true])
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_admin_deactivate_bloquea_la_cuenta(): void
    {
        $user = User::factory()->create(['email' => 'luis@mykesystems.test']);

        $this->artisan('admin:deactivate', ['email' => 'LUIS@mykesystems.test'])->assertSuccessful();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.deactivated', 'subject_id' => (string) $user->id]);
    }

    public function test_admin_deactivate_con_correo_inexistente_falla(): void
    {
        $this->artisan('admin:deactivate', ['email' => 'nadie@mykesystems.test'])->assertFailed();
    }
}
