<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * A version is edited under formula.edit and activated under formula.approve.
 * Whether the version's state allows the action is the service's decision.
 */
class FormulaVersionPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'formula';
    }

    public function create(User $user): bool
    {
        return $user->can('formula.edit');
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->can('formula.edit');
    }

    public function activate(User $user, Model $model): bool
    {
        return $user->can('formula.approve');
    }
}
