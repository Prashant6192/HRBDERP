<?php

declare(strict_types=1);

namespace App\Http\Requests\Formulation;

use Illuminate\Foundation\Http\FormRequest;

class CommitFormulaImportRequest extends FormRequest
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
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32,64}$/'],
            'assume_water_qs' => ['sometimes', 'boolean'],
            'activate' => ['sometimes', 'boolean'],
        ];
    }
}
