<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class UserPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'user';
    }

    /**
     * Everyone may read and edit their own profile without holding the
     * user-administration permission.
     */
    public function view(User $user, Model $model): bool
    {
        return $user->is($model) || $user->can('user.view');
    }

    /**
     * Nobody may delete themselves — an administrator who removes their own
     * account can leave the system with no way back in.
     */
    public function delete(User $user, Model $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $user->can('user.delete');
    }

    /**
     * Deactivation is the reversible alternative to deletion, and carries the
     * same self-lockout restriction.
     */
    public function deactivate(User $user, Model $model): bool
    {
        if ($user->is($model)) {
            return false;
        }

        return $user->can('user.edit');
    }

    /**
     * Only a Super Admin may hand out roles, and never to themselves — role
     * assignment is the one permission that can be used to grant every other.
     */
    public function assignRoles(User $user, Model $model): bool
    {
        return $user->isSuperAdmin() && ! $user->is($model);
    }

    public function impersonate(User $user, Model $model): bool
    {
        return $user->can('user.impersonate') && ! $user->is($model);
    }
}
