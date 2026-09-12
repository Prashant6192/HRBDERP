<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class WarehousePolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'warehouse';
    }

    public function deactivate(User $user, Model $model): bool
    {
        return $user->can('warehouse.deactivate') || $user->can('warehouse.edit');
    }
}
