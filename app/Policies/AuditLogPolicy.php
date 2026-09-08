<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit trail is readable by those with the permission, and writable by
 * nobody at all. The create/update/delete verbs deny unconditionally rather
 * than checking a permission, because no permission should be able to grant
 * them — see the AuditLog model.
 */
class AuditLogPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'audit';
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function restore(User $user, Model $model): bool
    {
        return false;
    }
}
