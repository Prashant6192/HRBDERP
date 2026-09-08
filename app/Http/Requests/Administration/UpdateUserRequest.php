<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use Illuminate\Validation\Rule;

class UpdateUserRequest extends StoreUserRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // An administrator editing a colleague does not retype their password.
        // Leaving it blank keeps the existing one; filling it in is how a
        // password is reset on their behalf. 'required' is dropped rather than
        // shadowed, since leaving both rules on the field contradicts itself.
        $rules['password'] = [
            'nullable',
            ...array_values(array_filter(
                $this->passwordRules(),
                static fn (mixed $rule): bool => $rule !== 'required',
            )),
        ];

        return $rules;
    }

    protected function uniqueRule(string $column): object
    {
        return Rule::unique('users', $column)
            ->ignore($this->route('user'))
            ->whereNull('deleted_at');
    }
}
