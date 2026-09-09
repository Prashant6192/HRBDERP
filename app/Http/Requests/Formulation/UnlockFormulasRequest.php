<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use Illuminate\Foundation\Http\FormRequest;

class UnlockFormulasRequest extends FormRequest
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
            'secret' => ['required', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['secret' => config('erp.formula_security.require_pin') ? 'PIN' : 'password'];
    }
}
