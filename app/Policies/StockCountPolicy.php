<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class StockCountPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'inventory';
    }

    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.count');
    }

    public function count(User $user, Model $model): bool
    {
        return $user->can('inventory.count');
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->can('inventory.approve_count');
    }
}
