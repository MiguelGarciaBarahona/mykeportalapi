<?php

namespace Database\Factories;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Administradores ficticios SOLO para pruebas automatizadas.
 * ⚠️ No usar en seeders de producción: el primer administrador se crea con `php artisan admin:create`.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Contraseña de las cuentas de prueba (cumple la política de contraseñas). */
    public const PASSWORD = 'Prueba-Segura-2026!';

    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make(self::PASSWORD),
            'remember_token' => Str::random(10),
            'role' => AdminRole::Admin,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => AdminRole::Owner]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /** Administrador con 2FA configurado y confirmado (secreto y códigos cifrados como lo hace Fortify). */
    public function withTwoFactor(?string $secret = null): static
    {
        return $this->state(function () use ($secret) {
            $secret ??= app(Google2FA::class)->generateSecretKey();

            return [
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt(json_encode([
                    'codigo-recuperacion-1', 'codigo-recuperacion-2', 'codigo-recuperacion-3',
                ])),
                'two_factor_confirmed_at' => now(),
            ];
        });
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
