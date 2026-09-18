<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Dispatch\Models\Dispatch;
use App\Models\User;

/**
 * Who may send goods out, and who may sign each step of it. Whether the
 * person is assigned to the facility in question is checked separately by
 * FacilityAccess.
 */
class DispatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('dispatch.view');
    }

    public function view(User $user, Dispatch $dispatch): bool
    {
        return $user->can('dispatch.view');
    }

    public function create(User $user): bool
    {
        return $user->can('dispatch.create');
    }

    public function update(User $user, Dispatch $dispatch): bool
    {
        return $user->can('dispatch.edit');
    }

    /** Record the invoice and what the IRP returned; keep its papers. */
    public function invoice(User $user, Dispatch $dispatch): bool
    {
        return $user->can('dispatch.invoice');
    }

    /** Let the goods go: the stock leaves the store. */
    public function dispatch(User $user, Dispatch $dispatch): bool
    {
        return $user->can('dispatch.dispatch');
    }

    public function deliver(User $user, Dispatch $dispatch): bool
    {
        return $user->can('dispatch.dispatch');
    }

    public function cancel(User $user, Dispatch $dispatch): bool
    {
        return $user->can('dispatch.cancel');
    }

    public function export(User $user): bool
    {
        return $user->can('dispatch.export');
    }
}
