<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Lots are read through the inventory module. Nobody creates or edits one by
 * hand: they come from receipts and manufacturing orders.
 */
class InventoryLotPolicy extends ModulePolicy
{
    protected function module(Model|string|null $model = null): string
    {
        return 'inventory';
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

    /**
     * Printing the sticker is a store or quality task.
     */
    public function printSticker(User $user, Model $model): bool
    {
        return $user->can('inventory.receive') || $user->can('qc.view');
    }
}
