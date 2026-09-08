<?php

declare(strict_types=1);

namespace App\Http\Requests\MasterData;

use Illuminate\Validation\Rule;

class UpdateItemRequest extends StoreItemRequest
{
    protected function uniqueCodeRule(): object
    {
        // The route parameter is named for the module — raw_material,
        // packaging_material or product — so take whichever one is bound.
        $item = $this->route('raw_material')
            ?? $this->route('packaging_material')
            ?? $this->route('product');

        return Rule::unique('items', 'code')
            ->ignore($item)
            ->whereNull('deleted_at');
    }
}
