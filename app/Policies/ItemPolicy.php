<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Items share a table, but not their permissions.
 *
 * A designer may read the packaging master and must not see raw materials; a
 * purchase manager is the reverse. Each item type therefore gets its own
 * policy subclass, so that Laravel can resolve the right one from the model
 * class alone — which is all it has for class-level abilities like viewAny and
 * create, where there is no record to inspect.
 *
 * Where there *is* a record, its own type decides, so a policy resolved from
 * one class can never authorise against another module's permission.
 */
abstract class ItemPolicy extends ModulePolicy
{
    abstract protected function itemType(): ItemType;

    protected function module(Model|string|null $model = null): string
    {
        if ($model instanceof Item) {
            return $model->type->permissionModule();
        }

        return $this->itemType()->permissionModule();
    }

    public function import(User $user): bool
    {
        return $user->can($this->module().'.import');
    }
}
