<?php

declare(strict_types=1);

namespace App\Http\Requests\Warehousing;

use Illuminate\Validation\Rule;

class UpdateWarehouseRequest extends StoreWarehouseRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['code'] = [
            'required', 'string', 'max:32',
            Rule::unique('warehouses', 'code')
                ->ignore($this->route('warehouse'))
                ->whereNull('deleted_at'),
        ];

        return $rules;
    }
}
