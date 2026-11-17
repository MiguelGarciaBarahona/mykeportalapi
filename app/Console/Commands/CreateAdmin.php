<?php

namespace App\Console\Commands;

use App\Audit\AuditLogger;
use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Crea un administrador desde la consola del servidor.
 *
 * Seguridad:
 *  - La contraseña SOLO se pide de forma oculta (nunca como argumento: quedaría en el historial).
 *  - El primer administrador del sistema siempre es owner.
 *  - El 2FA se configura obligatoriamente en el primer inicio de sesión.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
                            {--name= : Nombre completo}
                            {--email= : Correo de acceso}
                            {--role= : owner o admin}';

    protected $description = 'Crea un administrador del panel (la contraseña se solicita de forma oculta)';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Este comando debe ejecutarse de forma interactiva para pedir la contraseña de forma segura.');

            return self::FAILURE;
        }

        $isFirst = ! User::query()->exists();

        $name = trim((string) ($this->option('name') ?: $this->ask('Nombre completo')));
        $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('Correo de acceso'))));

        if ($isFirst) {
            $role = AdminRole::Owner->value;
            $this->info('Es el primer administrador: se creará con el rol owner.');
        } else {
            $role = (string) ($this->option('role') ?: $this->choice('Rol', [AdminRole::Admin->value, AdminRole::Owner->value], 0));
        }

        $password = (string) $this->secret('Contraseña (mínimo 12 caracteres, con mayúsculas, minúsculas, números y símbolos)');
        $confirmation = (string) $this->secret('Repite la contraseña');

        $validator = Validator::make(
            compact('name', 'email', 'role', 'password') + ['password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'min:2', 'max:100'],
                'email' => ['required', 'string', 'max:254', 'email:rfc,strict', Rule::unique('users', 'email')],
                'role' => ['required', Rule::in(array_column(AdminRole::cases(), 'value'))],
                'password' => ['required', 'string', Password::default(), 'confirmed'],
            ],
            [
                'email.unique' => 'Ya existe un administrador con ese correo.',
                'password.confirmed' => 'Las contraseñas no coinciden.',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // el cast "hashed" la cifra
            'role' => $role,
            'is_active' => true,
        ]);

        AuditLogger::record('admin.created', null, $user, (string) $user->id);

        $this->info("Administrador creado (rol: {$role}).");
        $this->line('En el primer inicio de sesión deberá configurar la verificación en dos pasos (obligatoria).');

        return self::SUCCESS;
    }
}
