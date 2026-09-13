<?php

declare(strict_types=1);

namespace App\Http\Requests\Quality;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideQcInspectionRequest extends FormRequest
{
    /**
     * Authorisation is answered before validation, so someone without the
     * right sees a 403 rather than a request for their PIN.
     */
    public function authorize(): bool
    {
        $ability = $this->routeIs('qc.reject') ? 'reject' : ($this->routeIs('qc.hold') ? 'hold' : 'approve');

        return (bool) $this->user()?->can($ability, $this->route('qcInspection'));
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
            'pin' => [config('erp.qc.require_pin', true) && ! $this->routeIs('qc.hold') ? 'required' : 'nullable', 'string', 'max:8'],
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
            'pin.required' => 'Enter your personal PIN to sign the decision.',
        ];
    }
}
