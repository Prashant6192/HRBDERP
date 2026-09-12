<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use App\Domain\Warehousing\Enums\WarehouseType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreWarehouseRequest extends FormRequest
{
    /**
     * Authorisation is handled by the controller's policy call, which runs
     * before validation. Returning true here does not open anything up.
     */
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
            'code' => ['required', 'string', 'max:32', Rule::unique('warehouses', 'code')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(WarehouseType::class)],
            'facility_id' => ['nullable', 'integer', Rule::exists('facilities', 'id')->whereNull('deleted_at')],
            'store_category_id' => ['nullable', 'integer', Rule::exists('store_categories', 'id')->where('is_active', true)],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],

            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'state' => ['nullable', 'string', 'max:128'],
            'pincode' => ['nullable', 'string', 'max:16'],
            'country' => ['nullable', 'string', 'max:128'],
            'gstin' => ['nullable', 'string', 'size:15'],

            'is_quarantine' => ['boolean'],
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
            'code.unique' => 'A warehouse with this code already exists.',
            'gstin.size' => 'A GSTIN is exactly 15 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'gstin' => $this->filled('gstin') ? strtoupper(trim((string) $this->input('gstin'))) : null,
        ]);
    }
}
