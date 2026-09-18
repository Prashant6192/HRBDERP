<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Domain\Dispatch\Enums\CustomerKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreCustomerRequest extends FormRequest
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
            // One GSTIN is one legal entity; two customers cannot share it.
            'gstin' => ['nullable', 'string', 'size:15', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $this->uniqueRule('gstin')],
            'pan' => ['nullable', 'string', 'size:10'],
            'kind' => ['required', Rule::enum(CustomerKind::class)],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
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
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
            'gstin.regex' => 'That does not look like a GSTIN (two-digit state code, PAN, entity digit, Z, check character).',
            'gstin.unique' => 'Another customer already carries this GSTIN.',
            'client_id.exists' => 'Choose a contract client on file.',
        ];
    }

    protected function uniqueRule(string $column): Unique
    {
        $rule = Rule::unique('customers', $column)->whereNull('deleted_at');
        $customer = $this->route('customer');

        return $customer ? $rule->ignore($customer) : $rule;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
            'pan' => $this->filled('pan') ? strtoupper(trim((string) $this->input('pan'))) : null,
            'client_id' => $this->filled('client_id') ? $this->input('client_id') : null,
            'is_active' => $this->boolean('is_active', true),
        ]);
    }
}
