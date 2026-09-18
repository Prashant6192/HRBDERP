<?php

declare(strict_types=1);

namespace App\Http\Requests\Dispatch;

use App\Domain\Dispatch\Support\GstStateCodes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDispatchRequest extends FormRequest
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
            'warehouse_id' => [
                'required', 'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('is_active', true)->where('is_system', false)->where('is_quarantine', false)->whereNull('deleted_at')),
            ],
            'customer_id' => [
                'required', 'integer',
                Rule::exists('customers', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],
            'reference' => ['nullable', 'string', 'max:64'],
            'place_of_supply' => ['nullable', 'string', 'size:2', Rule::in(array_keys(GstStateCodes::NAMES))],
            'other_charges' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'ship_to' => ['nullable', 'array'],
            'ship_to.name' => ['nullable', 'string', 'max:255'],
            'ship_to.gstin' => ['nullable', 'string', 'size:15'],
            'ship_to.address_line_1' => ['nullable', 'string', 'max:255'],
            'ship_to.address_line_2' => ['nullable', 'string', 'max:255'],
            'ship_to.city' => ['nullable', 'string', 'max:128'],
            'ship_to.state' => ['nullable', 'string', 'max:128'],
            'ship_to.pincode' => ['nullable', 'string', 'max:16'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.lot_id' => ['required', 'integer', Rule::exists('inventory_lots', 'id')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.gst_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.hsn_code' => ['nullable', 'string', 'max:16'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'Choose an active finished goods store.',
            'customer_id.exists' => 'Choose an active customer.',
            'lines.required' => 'Add at least one batch to the dispatch.',
            'lines.*.lot_id.required' => 'Choose the batch that is going.',
            'lines.*.quantity.gt' => 'The quantity must be greater than zero.',
            'lines.*.unit_price.required' => 'Give the unit price on the invoice.',
        ];
    }
}
