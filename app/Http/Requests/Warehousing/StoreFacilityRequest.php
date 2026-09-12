<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use App\Domain\Warehousing\Enums\FacilityCapability;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The onboarding wizard's payload: details, capabilities, the store
 * checklist and (optionally) the first employees.
 */
class StoreFacilityRequest extends FormRequest
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
            ...FacilityRules::details(),
            ...FacilityRules::capabilities(),
            'opening_stock_enabled' => ['boolean'],
            'is_active' => ['boolean'],

            'stores' => ['array'],
            'stores.*.store_category_id' => ['required', 'integer', Rule::exists('store_categories', 'id')->where('is_active', true)],
            'stores.*.name' => ['nullable', 'string', 'max:120'],
            'stores.*.code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/', 'distinct:ignore_case', Rule::unique('warehouses', 'code')],
            'stores.*.default_location' => ['nullable', 'string', 'max:64'],
            'stores.*.manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'stores.*.is_active' => ['boolean'],

            'employees' => ['array'],
            'employees.*.user_id' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')],
            'employees.*.is_primary' => ['boolean'],
            'employees.*.designation' => ['nullable', 'string', 'max:128'],

            'opening_stock' => ['nullable', Rule::in(['now', 'later'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stores.*.code.unique' => 'A store with this code already exists.',
            'stores.*.code.distinct' => 'Two stores in the list share the same code.',
            'stores.*.code.regex' => 'Store codes use letters, digits and dashes only.',
            'employees.*.user_id.distinct' => 'The same person is listed twice.',
            ...FacilityRules::messages(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => $this->filled('code') ? strtoupper(trim((string) $this->input('code'))) : null,
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
        ]);

        foreach (FacilityCapability::cases() as $capability) {
            if ($this->has($capability->value)) {
                $this->merge([$capability->value => filter_var($this->input($capability->value), FILTER_VALIDATE_BOOL)]);
            }
        }
    }
}
