<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Inventory\Models\StockTransfer;
use App\Models\User;

/**
 * Who may move stock between facilities, and who may sign each leg.
 * Whether the person is assigned to the facility in question is checked
 * separately by FacilityAccess.
 */
class StockTransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.transfer');
    }

    public function request(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.transfer');
    }

    public function approve(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.approve_transfer');
    }

    public function dispatch(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.transfer');
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.receive_transfer');
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $user->can('inventory.transfer') || $user->can('inventory.approve_transfer');
    }
}
