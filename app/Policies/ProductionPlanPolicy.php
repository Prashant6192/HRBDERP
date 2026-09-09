<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Planning a batch and raising its material requests.
 */
class ProductionPlanPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'planning';
    }

    public function check(User $user, Model $model): bool
    {
        return $user->can('planning.create') || $user->can('planning.edit');
    }

    public function request(User $user, Model $model): bool
    {
        return $user->can('planning.create') || $user->can('planning.edit');
    }

    public function cancel(User $user, Model $model): bool
    {
        return $user->can('planning.cancel') || $user->can('planning.edit');
    }
}
