<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may do what with a formula.
 *
 * These answer the permission question only. Seeing a recipe additionally
 * requires the second-factor unlock, which the EnsureFormulaUnlocked
 * middleware checks on every route that would load ingredients.
 */
class FormulaPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'formula';
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->can('formula.approve');
    }

    public function archive(User $user, Model $model): bool
    {
        return $user->can('formula.archive');
    }

    public function import(User $user): bool
    {
        return $user->can('formula.import');
    }

    public function scale(User $user, Model $model): bool
    {
        return $user->can('formula.view');
    }
}
