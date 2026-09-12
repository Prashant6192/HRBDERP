<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOpeningStockRequest extends FormRequest
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
        $facilityId = $this->route('facility')?->id;

        return [
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('facility_id', $facilityId)->where('is_active', true)->where('is_system', false)->whereNull('deleted_at')),
            ],
            'as_of' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')],
            'lines.*.batch_number' => ['nullable', 'string', 'max:64'],
            'lines.*.manufactured_at' => ['nullable', 'date'],
            'lines.*.expiry_at' => ['nullable', 'date'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.remarks' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Choose an active store at this facility.',
            'as_of.before_or_equal' => 'Opening stock cannot be dated in the future.',
            'lines.required' => 'Add at least one line.',
            'lines.*.quantity.gt' => 'Each quantity must be greater than zero.',
        ];
    }
}
