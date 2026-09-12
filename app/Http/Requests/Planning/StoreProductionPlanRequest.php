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
        ];
    }
}
