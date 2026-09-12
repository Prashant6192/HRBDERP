<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class FacilityPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'facility';
    }

    public function deactivate(User $user, Model $model): bool
    {
        return $user->can('facility.deactivate');
    }

    /**
     * A facility is switched off, never removed: its stores, stock history
     * and people all point at it.
     */
    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function assignEmployees(User $user, Model $model): bool
    {
        return $user->can('user.assign_facility') || $user->can('user.assign_store');
    }

    public function bookOpeningStock(User $user, Model $model): bool
    {
        return $user->can('inventory.opening_stock');
    }
}
