<?php

declare(strict_types=1);

namespace App\Http\Requests\Contract;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreClientRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            // One GSTIN is one legal entity; two clients cannot share it.
            'gstin' => ['nullable', 'string', 'size:15', $this->uniqueRule('gstin')],
            'pan' => ['nullable', 'string', 'size:10'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'billing_address_line_1' => ['nullable', 'string', 'max:255'],
            'billing_address_line_2' => ['nullable', 'string', 'max:255'],
            'billing_city' => ['nullable', 'string', 'max:128'],
            'billing_state' => ['nullable', 'string', 'max:128'],
            'billing_pincode' => ['nullable', 'string', 'max:16'],
            'shipping_address_line_1' => ['nullable', 'string', 'max:255'],
            'shipping_address_line_2' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['nullable', 'string', 'max:128'],
            'shipping_state' => ['nullable', 'string', 'max:128'],
            'shipping_pincode' => ['nullable', 'string', 'max:16'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'agreement_ref' => ['nullable', 'string', 'max:128'],
            'agreement_expires_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function uniqueRule(string $column): Unique
    {
        $rule = Rule::unique('clients', $column)->whereNull('deleted_at');
        $client = $this->route('client');

        return $client ? $rule->ignore($client) : $rule;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
            'pan' => $this->filled('pan') ? strtoupper(trim((string) $this->input('pan'))) : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.unique' => 'A client with this GSTIN is already on file.',
            'gstin.size' => 'A GSTIN is 15 characters.',
        ];
    }
}
