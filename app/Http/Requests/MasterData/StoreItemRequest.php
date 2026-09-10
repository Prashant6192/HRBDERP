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

            // The stock-control figures are what the Moderate / Low / Critically
            // low alerts, the store dashboard and the planning shortfalls are
            // built on. A material without them can only ever be "healthy" or
            // "out of stock", so for materials they are part of the record.
            'reorder_level' => [$this->isMaterial() ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'minimum_stock' => [$this->isMaterial() ? 'required' : 'nullable', 'numeric', 'gte:0', 'lte:reorder_level'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0', 'gte:minimum_stock', 'gte:reorder_level'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * Raw and packaging materials are bought against thresholds; finished
     * goods are made to order and may leave them blank.
     */
    protected function isMaterial(): bool
    {
        $route = $this->route()?->getName() ?? '';

        return str_starts_with($route, 'raw-materials.') || str_starts_with($route, 'packaging-materials.');
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
            'reorder_level.required' => 'Enter the reorder level — the quantity at which this material counts as low and should be ordered.',
            'reorder_level.gt' => 'The reorder level must be greater than zero.',
            'minimum_stock.required' => 'Enter the minimum stock — the quantity below which this material is critically low.',
            'minimum_stock.lte' => 'The minimum stock (critical) cannot be above the reorder level (low).',
            'maximum_stock.gte' => 'The maximum stock level cannot be below the minimum or the reorder level.',
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
