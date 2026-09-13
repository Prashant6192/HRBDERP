<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DocumentPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'document';
    }

    public function approve(User $user, Model $model): bool
    {
        return $user->can('document.approve');
    }

    public function withdraw(User $user, Model $model): bool
    {
        return $user->can('document.withdraw');
    }
}
