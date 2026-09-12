<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9\-]+$/', Rule::unique('warehouses', 'code')->ignore($this->route('warehouse')?->id)],
            'store_category_id' => ['sometimes', 'integer', Rule::exists('store_categories', 'id')],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'is_active' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
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
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }
}
