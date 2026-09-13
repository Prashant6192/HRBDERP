<?php

declare(strict_types=1);

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;

class StoreQcSpecRequest extends FormRequest
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
            'parameters' => ['required', 'array', 'min:1'],
            'parameters.*.name' => ['required', 'string', 'max:128'],
            'parameters.*.min' => ['nullable', 'string', 'max:32'],
            'parameters.*.max' => ['nullable', 'string', 'max:32'],
            'parameters.*.target' => ['nullable', 'string', 'max:64'],
            'parameters.*.unit' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parameters.required' => 'Add at least one parameter to check.',
            'parameters.*.name.required' => 'Every parameter needs a name.',
        ];
    }
}
