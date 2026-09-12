<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Adding one store to an existing facility.
 */
class StoreStoreRequest extends FormRequest
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
            'store_category_id' => ['required', 'integer', Rule::exists('store_categories', 'id')->where('is_active', true)],
            'name' => ['nullable', 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('warehouses', 'code')],
            'default_location' => ['nullable', 'string', 'max:64'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'A store with this code already exists.',
            'code.regex' => 'Store codes use letters, digits and dashes only.',
            'store_category_id.exists' => 'Choose an active store category.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
