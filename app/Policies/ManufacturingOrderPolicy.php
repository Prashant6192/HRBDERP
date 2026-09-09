<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Approval holds stock, so it is a separate right from running the kettle.
 */
class ManufacturingOrderPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'production';
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->can('production.approve');
    }

    public function start(User $user, Model $model): bool
    {
        return $user->can('production.consume');
    }

    public function complete(User $user, Model $model): bool
    {
        return $user->can('production.consume');
    }

    public function cancel(User $user, Model $model): bool
    {
        return $user->can('production.cancel');
    }
}
