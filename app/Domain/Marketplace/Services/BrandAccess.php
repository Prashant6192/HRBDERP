<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Shipment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which brands' labels a person may see and upload.
 *
 * Everyone in the company sees every brand. An outside agency sees only
 * the brands it has been given on its user record, and nothing at all
 * until it has been given one.
 */
class BrandAccess
{
    public function isRestricted(User $user): bool
    {
        return ! $user->isSuperAdmin() && $user->hasRole(RoleName::EcommerceAgency->value);
    }

    /**
     * The brands this person is limited to, or null for all of them.
     *
     * @return list<int>|null
     */
    public function brandIds(User $user): ?array
    {
        if (! $this->isRestricted($user)) {
            return null;
        }

        return $user->brands()->pluck('brands.id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function canUse(User $user, Brand|int $brand): bool
    {
        $ids = $this->brandIds($user);

        return $ids === null || in_array($brand instanceof Brand ? $brand->id : $brand, $ids, true);
    }

    public function assertCanUse(User $user, Brand $brand): void
    {
        if (! $this->canUse($user, $brand)) {
            throw new AuthorizationException("You do not upload labels for {$brand->name}.");
        }
    }

    /**
     * @param  Builder<Brand>  $query
     * @return Builder<Brand>
     */
    public function scopeBrands(User $user, Builder $query): Builder
    {
        $ids = $this->brandIds($user);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    /**
     * @template TModel of LabelBatch|Shipment
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeByBrand(User $user, Builder $query): Builder
    {
        $ids = $this->brandIds($user);

        return $ids === null ? $query : $query->whereIn($query->getModel()->getTable().'.brand_id', $ids);
    }
}
