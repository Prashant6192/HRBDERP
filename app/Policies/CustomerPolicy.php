<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers are the dispatch module's own master: whoever may write a
 * consignment up may add who it goes to.
 */
class CustomerPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'dispatch';
    }

    /** A customer with history is deactivated, never removed. */
    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
