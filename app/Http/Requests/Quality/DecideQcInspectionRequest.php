<?php

declare(strict_types=1);

namespace App\Http\Requests\Quality;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideQcInspectionRequest extends FormRequest
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
        // A rejection must say why; it is the one decision somebody will
        // come back to argue about.
        $remarks = $this->routeIs('qc.reject')
            ? ['required', 'string', 'min:5', 'max:2000']
            : ['nullable', 'string', 'max:2000'];

        return [
            'remarks' => $remarks,
            'parameters' => ['nullable', 'array'],
            'parameters.*.name' => ['required_with:parameters', 'string', 'max:64'],
            'parameters.*.value' => ['nullable', 'string', 'max:128'],
            'parameters.*.passed' => ['nullable', 'boolean'],
            'destination_warehouse_id' => [
                'nullable', 'integer',
                Rule::exists('warehouses', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->where('is_quarantine', false)->whereNull('deleted_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remarks.required' => 'Say why the batch is being rejected.',
        ];
    }
}
