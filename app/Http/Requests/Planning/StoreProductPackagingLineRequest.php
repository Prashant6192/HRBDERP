<?php

declare(strict_types=1);

namespace App\Http\Requests\Planning;

use App\Domain\MasterData\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductPackagingLineRequest extends FormRequest
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
            'packaging_material_id' => [
                'required', 'integer',
                Rule::exists('items', 'id')->where(fn ($q) => $q
                    ->where('type', ItemType::PackagingMaterial->value)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')),
                Rule::unique('product_packaging_lines', 'packaging_material_id')
                    ->where('product_id', $this->route('product')?->getKey()),
            ],
            'quantity_per_unit' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'packaging_material_id.exists' => 'Choose an active packaging material.',
            'packaging_material_id.unique' => 'That packaging material is already on the list.',
            'quantity_per_unit.gt' => 'Quantity per unit must be greater than zero (use 0.01 for one carton per 100).',
        ];
    }
}
