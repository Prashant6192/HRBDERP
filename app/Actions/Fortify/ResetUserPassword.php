<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * A deactivated account is refused even with a valid link, since one
     * may have been issued before the account was withdrawn. Saying so
     * here reveals nothing: only the holder of that link can see it.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'This account is not active, so its password cannot be reset. Ask your administrator to reactivate it.',
            ]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
            // They have just chosen their own password; nothing is left to force.
            'must_change_password' => false,
        ])->save();
    }
}
