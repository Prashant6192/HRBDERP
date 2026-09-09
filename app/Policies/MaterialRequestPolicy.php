<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A PMR is read by the store and by purchase, so it sits under the
 * purchase module; planners see it through planning.view as well.
 */
class MaterialRequestPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'purchase';
    }

    public function viewAny(User $user): bool
    {
        return $user->can('purchase.view') || $user->can('planning.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function print(User $user, Model $model): bool
    {
        return $this->viewAny($user);
    }

    public function cancel(User $user, Model $model): bool
    {
        return $user->can('planning.cancel') || $user->can('planning.edit') || $user->can('purchase.edit');
    }
}
