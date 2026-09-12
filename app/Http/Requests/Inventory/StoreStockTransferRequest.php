<?php

declare(strict_types=1);

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockTransferRequest extends FormRequest
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
        $activeStore = fn ($q) => $q->where('is_active', true)->where('is_system', false)->whereNotNull('facility_id')->whereNull('deleted_at');

        return [
            'source_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where($activeStore)],
            'destination_warehouse_id' => ['required', 'integer', 'different:source_warehouse_id', Rule::exists('warehouses', 'id')->where($activeStore)],
            'requires_inspection' => ['boolean'],
            'expected_at' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'submit' => ['nullable', Rule::in(['draft', 'request'])],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')],
            'lines.*.lot_id' => ['nullable', 'integer', Rule::exists('inventory_lots', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destination_warehouse_id.different' => 'The destination must be a different store from the source.',
            'lines.required' => 'Add at least one item to transfer.',
            'lines.*.quantity.gt' => 'Each quantity must be greater than zero.',
        ];
    }
}
