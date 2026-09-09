<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting the formula PIN requires the account password, so a walk-up at an
 * unlocked desk cannot replace it.
 */
class SetFormulaPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
            'pin' => ['required', 'string', 'digits_between:4,8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.digits_between' => 'The PIN must be 4 to 8 digits.',
            'pin.confirmed' => 'The two PINs do not match.',
            'password.current_password' => 'That is not your account password.',
        ];
    }
}
