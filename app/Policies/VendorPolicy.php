<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Database\Eloquent\Model;

class VendorPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'vendor';
    }
}
