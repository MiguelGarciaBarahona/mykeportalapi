<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Ayudas para probar el panel en su dominio real (admin.mykesystems.test en pruebas)
 * con las cabeceras que envía el navegador desde la SPA.
 */
trait InteractsWithAdminPanel
{
    protected string $adminHost = 'http://admin.mykesystems.test';

    protected function adminUrl(string $path): string
    {
        return $this->adminHost.'/api/admin'.$path;
    }

    /** @return array<string, string> */
    protected function adminHeaders(): array
    {
        return [
            'Origin' => $this->adminHost,
            'Referer' => $this->adminHost.'/',
            'Accept' => 'application/json',
        ];
    }

    protected function adminGet(string $path): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->getJson($this->adminUrl($path));
    }

    /** @param array<string, mixed> $data */
    protected function adminPost(string $path, array $data = []): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->postJson($this->adminUrl($path), $data);
    }

    /** @param array<string, mixed> $data */
    protected function adminPatch(string $path, array $data = []): TestResponse
    {
        return $this->withHeaders($this->adminHeaders())->patchJson($this->adminUrl($path), $data);
    }

    protected function login(User $user, string $password = UserFactory::PASSWORD): TestResponse
    {
        return $this->adminPost('/auth/login', ['email' => $user->email, 'password' => $password]);
    }

    /** Código TOTP vigente para el usuario (como lo generaría su app de autenticación). */
    protected function totp(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }

    /** Inicia sesión completa (contraseña + 2FA). */
    protected function loginWithTwoFactor(User $user): void
    {
        $this->login($user)->assertOk()->assertJson(['two_factor' => true]);
        $this->adminPost('/auth/two-factor-challenge', ['code' => $this->totp($user)])->assertNoContent();
    }
}
