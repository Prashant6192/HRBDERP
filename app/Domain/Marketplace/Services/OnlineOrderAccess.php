<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a person may work the online orders.
 *
 * The depot's people — whoever prints labels, scans parcels, hands them to
 * the courier, takes returns or runs the orders — work them at every
 * dispatch facility, whatever their facility assignment points at: a
 * label filed under one facility record must never leave the depot
 * manager unable to print or scan it. Someone who may only look is held
 * to their facilities; the agency is held to its brands instead.
 */
final class OnlineOrderAccess
{
    private const array WORK = [
        'marketplace.print',
        'marketplace.pack',
        'marketplace.handover',
        'marketplace.return',
        'marketplace.manage',
    ];

    public function __construct(
        private readonly FacilityAccess $facilities,
        private readonly BrandAccess $brands,
    ) {}

    /**
     * Works the online orders at every facility.
     */
    public function everywhere(User $user): bool
    {
        if ($this->facilities->isCompanyWide($user)) {
            return true;
        }

        if ($this->brands->isRestricted($user)) {
            return false;
        }

        foreach (self::WORK as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The facilities whose online orders the person sees, or null for all.
     *
     * @return list<int>|null
     */
    public function facilityIds(User $user): ?array
    {
        if ($this->everywhere($user) || $this->brands->isRestricted($user)) {
            return null;
        }

        return $this->facilities->facilityIds($user);
    }

    public function canWorkAt(User $user, Facility|int|null $facility): bool
    {
        return $facility === null || $this->everywhere($user) || $this->facilities->canWorkAt($user, $facility);
    }

    public function canWorkIn(User $user, ?Warehouse $store): bool
    {
        return $store === null || $this->everywhere($user) || $this->facilities->canWorkIn($user, $store);
    }

    /**
     * @param  Builder<Facility>  $query
     * @return Builder<Facility>
     */
    public function scopeFacilities(User $user, Builder $query): Builder
    {
        return $this->everywhere($user) ? $query : $this->facilities->scopeFacilities($user, $query);
    }

    /**
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
     */
    public function scopeStores(User $user, Builder $query): Builder
    {
        return $this->everywhere($user) ? $query : $this->facilities->scopeStores($user, $query);
    }
}
