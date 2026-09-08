<?php

declare(strict_types=1);

namespace App\Http\Requests\Procurement;

use Illuminate\Validation\Rule;

class UpdateVendorRequest extends StoreVendorRequest
{
    protected function uniqueRule(string $column): object
    {
        return Rule::unique('vendors', $column)
            ->ignore($this->route('vendor'))
            ->whereNull('deleted_at');
    }
}
