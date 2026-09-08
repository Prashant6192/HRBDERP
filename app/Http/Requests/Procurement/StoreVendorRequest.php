<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVendorRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32', $this->uniqueRule('code')],
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],

            // A GSTIN identifies one legal entity, so two vendor records
            // sharing one is a duplicate rather than a second supplier.
            'gstin' => ['nullable', 'string', 'size:15', $this->uniqueRule('gstin')],
            'pan' => ['nullable', 'string', 'size:10'],

            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'pincode' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:128'],

            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'supply_type' => ['required', Rule::in(['raw_material', 'packaging', 'services', 'mixed'])],

            'is_approved' => ['boolean'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function uniqueRule(string $column): object
    {
        return Rule::unique('vendors', $column)->whereNull('deleted_at');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.unique' => 'Another vendor is already registered with this GSTIN.',
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
            'pan.size' => 'A PAN is exactly 10 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
            'pan' => $this->filled('pan') ? strtoupper(trim((string) $this->input('pan'))) : null,
        ]);
    }
}
