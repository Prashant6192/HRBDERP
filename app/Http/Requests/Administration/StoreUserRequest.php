<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Concerns\PasswordValidationRules;
use App\Domain\Identity\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreUserRequest extends FormRequest
{
    use PasswordValidationRules;

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
            'employee_code' => ['nullable', 'string', 'max:32', $this->uniqueRule('employee_code')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', $this->uniqueRule('email')],
            'password' => $this->passwordRules(),

            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'designation' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'status' => ['required', new Enum(UserStatus::class)],

            // Roles are assigned separately and only by a Super Admin, so they
            // are validated but authorised by the controller against the
            // assignRoles policy rather than by holding user.edit.
            'roles' => ['array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],

            'must_change_password' => ['boolean'],
        ];
    }

    protected function uniqueRule(string $column): object
    {
        return Rule::unique('users', $column)->whereNull('deleted_at');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
