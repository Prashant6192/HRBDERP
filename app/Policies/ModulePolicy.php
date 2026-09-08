<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared shape for the ERP's per-module policies.
 *
 * Every module guards the same handful of verbs with the same naming
 * convention — "<module>.view", "<module>.create" and so on — so the mapping
 * lives here once. A module with an unusual rule overrides the one method it
 * differs on rather than restating the other five.
 *
 * Super Admin never reaches these methods: a Gate::before hook in
 * AuthServiceProvider answers first.
 */
abstract class ModulePolicy
{
    /**
     * The permission module this policy guards, e.g. 'warehouse'.
     */
    abstract protected function module(Model|string|null $model = null): string;

    public function viewAny(User $user): bool
    {
        return $user->can($this->module().'.view');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->can($this->module($model).'.view');
    }

    public function create(User $user): bool
    {
        return $user->can($this->module().'.create');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can($this->module($model).'.edit');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can($this->module($model).'.delete');
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->can($this->module($model).'.delete');
    }

    /**
     * Permanent deletion is deliberately nobody's right by default.
     *
     * ERP records are referenced by ledgers, batches and approvals long after
     * they stop being used; they are deactivated or soft-deleted instead.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    public function export(User $user): bool
    {
        return $user->can($this->module().'.export');
    }
}
