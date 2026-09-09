<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class QcInspectionPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'qc';
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->can('qc.approve');
    }

    public function reject(User $user, Model $model): bool
    {
        return $user->can('qc.reject');
    }

    public function hold(User $user, Model $model): bool
    {
        return $user->can('qc.approve') || $user->can('qc.reject');
    }
}
