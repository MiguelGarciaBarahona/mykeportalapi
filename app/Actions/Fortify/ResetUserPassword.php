<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\SessionTerminator;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Restablece la contraseña olvidada y cierra todas las sesiones abiertas del usuario.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'], // el cast "hashed" la cifra
        ])->setRememberToken(null);
        $user->save();

        SessionTerminator::forUser($user);
    }
}
