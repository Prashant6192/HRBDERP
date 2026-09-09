<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation shared by raw materials, packaging materials and products.
 *
 * The three modules show different fields, but they write to the same table
 * and must agree on what a valid row looks like — particularly the numeric
 * ones, which are validated as decimal strings rather than being cast through
 * a float on the way in.
 */
class StoreItemRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64', $this->uniqueCodeRule()],
            'name' => ['required', 'string', 'max:255'],
            'inci_name' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')],
            'description' => ['nullable', 'string', 'max:2000'],

            'stock_uom_id' => ['required', 'integer', Rule::exists('uoms', 'id')],
            'purchase_uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')],
            'density_g_per_ml' => ['nullable', 'numeric', 'gt:0', 'decimal:0,6'],

            'hsn_code' => ['nullable', 'string', 'max:16'],
            'gst_rate' => ['nullable', 'numeric', 'between:0,100'],
            'standard_cost' => ['nullable', 'numeric', 'min:0'],

            'brand' => ['nullable', 'string', 'max:128'],
            'mrp' => ['nullable', 'numeric', 'min:0'],
            'net_content' => ['nullable', 'numeric', 'gt:0'],
            'net_content_uom_id' => ['nullable', 'integer', Rule::exists('uoms', 'id')],
            'barcode' => ['nullable', 'string', 'max:64'],

            'is_batch_tracked' => ['boolean'],
            'requires_qc' => ['boolean'],
            'shelf_life_days' => ['nullable', 'integer', 'min:1', 'max:36500'],

            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0', 'gte:minimum_stock'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],

            'is_active' => ['boolean'],
        ];
    }

    protected function uniqueCodeRule(): object
    {
        return Rule::unique('items', 'code')->whereNull('deleted_at');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'An item with this code already exists.',
            'maximum_stock.gte' => 'The maximum stock level cannot be below the minimum.',
            'density_g_per_ml.gt' => 'Density must be greater than zero.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
        ]);
    }
}
