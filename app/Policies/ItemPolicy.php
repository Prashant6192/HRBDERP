<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Models\RawMaterial;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Items share a table, but not their permissions.
 *
 * A designer may read the packaging master and must not see raw materials; a
 * purchase manager is the reverse. So the permission module is decided by the
 * item's type — taken from the record when there is one, and from the model
 * class when authorising something typeless like "create".
 */
class ItemPolicy extends ModulePolicy
{
    /**
     * Which item type each concrete model represents.
     *
     * @var array<class-string, ItemType>
     */
    private const MODEL_TYPES = [
        RawMaterial::class => ItemType::RawMaterial,
        PackagingMaterial::class => ItemType::PackagingMaterial,
        Product::class => ItemType::FinishedGood,
    ];

    protected function module(Model|string|null $model = null): string
    {
        if ($model instanceof Item) {
            return $model->type->permissionModule();
        }

        if (is_string($model) && isset(self::MODEL_TYPES[$model])) {
            return self::MODEL_TYPES[$model]->permissionModule();
        }

        // Authorising against the base Item with no record in hand. The caller
        // should pass the concrete class; falling back to the most restrictive
        // module is safer than guessing the most permissive.
        return ItemType::RawMaterial->permissionModule();
    }

    /**
     * Item creation is authorised against the concrete class, which the
     * controller passes as the second argument to Gate::authorize.
     */
    public function create(User $user, Model|string|null $model = null): bool
    {
        return $user->can($this->module($model).'.create');
    }

    public function createOfType(User $user, ItemType $type): bool
    {
        return $user->can($type->permissionModule().'.create');
    }

    public function import(User $user, Model|string|null $model = null): bool
    {
        return $user->can($this->module($model).'.import');
    }

    public function exportOfType(User $user, ItemType $type): bool
    {
        return $user->can($type->permissionModule().'.export');
    }
}
