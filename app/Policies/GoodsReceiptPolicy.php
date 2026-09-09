<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Receiving goods is a warehouse job, not a purchasing one.
 *
 * The module is 'purchase' because a receipt closes a purchase, but the
 * verbs that create and post a receipt map to purchase.receive rather than
 * purchase.create: the warehouse manager who books in a delivery does not
 * thereby gain the right to raise purchase orders.
 */
class GoodsReceiptPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'purchase';
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.receive');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->can('purchase.receive');
    }

    public function post(User $user, Model $model): bool
    {
        return $user->can('purchase.receive');
    }

    public function cancel(User $user, Model $model): bool
    {
        return $user->can('purchase.receive');
    }
}
