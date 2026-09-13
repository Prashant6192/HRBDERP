<?php

declare(strict_types=1);

namespace App\Http\Requests\Planning;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductionPlanRequest extends FormRequest
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
            'formula_id' => [
                'required', 'integer',
                Rule::exists('formulas', 'id')->where(fn ($q) => $q->where('status', 'active')->whereNull('deleted_at')),
            ],
            'facility_id' => [
                'nullable', 'integer',
                Rule::exists('facilities', 'id')->where(fn ($q) => $q->where('is_active', true)->where('can_manufacture', true)->whereNull('deleted_at')),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'uom_id' => [
                'required', 'integer',
                Rule::exists('uoms', 'id')->where(fn ($q) => $q->where('is_active', true)->whereIn('dimension', ['mass', 'volume'])),
            ],
            'planned_start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Whose batch it is. A third-party batch names the client, and
            // says which materials the client supplies.
            'manufacturing_type' => ['nullable', Rule::in(['own', 'third_party'])],
            'client_id' => [
                'nullable', 'integer', 'required_if:manufacturing_type,third_party',
                Rule::exists('clients', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],
            'client_po_ref' => ['nullable', 'string', 'max:64'],
            'client_product_name' => ['nullable', 'string', 'max:255'],
            'required_delivery_at' => ['nullable', 'date'],
            'material_source' => ['nullable', Rule::in(['company', 'client', 'mixed'])],
            'client_supplied_item_ids' => ['nullable', 'array'],
            'client_supplied_item_ids.*' => ['integer', Rule::exists('items', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'formula_id.exists' => 'Choose a formula with an active recipe.',
            'facility_id.exists' => 'Choose an active facility with manufacturing enabled.',
            'quantity.gt' => 'The batch quantity must be greater than zero.',
            'uom_id.exists' => 'The batch unit must be a unit of mass or volume.',
            'client_id.required_if' => 'Choose the client the batch is made for.',
            'client_id.exists' => 'Choose an active client.',
        ];
    }
}
