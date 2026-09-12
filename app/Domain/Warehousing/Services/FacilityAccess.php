<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Warehousing\Exceptions\FacilityAccessDeniedException;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The second half of authorisation.
 *
 * A role permission says what a person may do; an assignment says where.
 * Both must hold for a stock action at a facility. Company-wide roles
 * (Super Admin, Owner, Director, Management) work everywhere. A person
 * with no assignment at all is not narrowed — assignments restrict, they
 * do not grant — so nothing that worked before facilities existed stops
 * working until an administrator assigns people.
 */
class FacilityAccess
{
    /** @var list<RoleName> */
    private const COMPANY_WIDE_ROLES = [RoleName::SuperAdmin, RoleName::Owner, RoleName::Director, RoleName::Management];

    /** @var array<int, Collection<int, EmployeeAssignment>> */
    private array $cache = [];

    public function isCompanyWide(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ($user->hasAnyRole(array_map(static fn (RoleName $r): string => $r->value, self::COMPANY_WIDE_ROLES))) {
            return true;
        }

        return $this->assignmentsOf($user)->isEmpty();
    }

    /**
     * The facilities a user may act at, or null when unrestricted.
     *
     * @return list<int>|null
     */
    public function facilityIds(User $user): ?array
    {
        if ($this->isCompanyWide($user)) {
            return null;
        }

        return $this->assignmentsOf($user)->pluck('facility_id')->unique()->values()->all();
    }

    public function canWorkAt(User $user, Facility|int $facility): bool
    {
        $ids = $this->facilityIds($user);

        return $ids === null || in_array($facility instanceof Facility ? $facility->id : $facility, $ids, strict: true);
    }

    /**
     * Whether a user may move or book stock in a store: an assignment to its
     * facility as a whole, or to that store in particular.
     */
    public function canWorkIn(User $user, Warehouse $store): bool
    {
        if ($this->isCompanyWide($user) || $store->is_system) {
            return true;
        }

        if ($store->facility_id === null) {
            return true;
        }

        return $this->assignmentsOf($user)->contains(
            fn (EmployeeAssignment $a): bool => $a->facility_id === $store->facility_id
                && ($a->store_id === null || $a->store_id === $store->id),
        );
    }

    /**
     * @throws FacilityAccessDeniedException
     */
    public function assertCanWorkAt(User $user, Facility $facility): void
    {
        if (! $this->canWorkAt($user, $facility)) {
            throw new FacilityAccessDeniedException("You are not assigned to {$facility->name}. Ask an administrator for an assignment there.");
        }
    }

    /**
     * @throws FacilityAccessDeniedException
     */
    public function assertCanWorkIn(User $user, Warehouse $store): void
    {
        if (! $this->canWorkIn($user, $store)) {
            $where = $store->facility_id !== null ? " at {$store->facility?->name}" : '';
            throw new FacilityAccessDeniedException("You are not assigned to {$store->name}{$where}. Ask an administrator for an assignment there.");
        }
    }

    /**
     * Narrow a facility query to what the user may see.
     *
     * @param  Builder<Facility>  $query
     * @return Builder<Facility>
     */
    public function scopeFacilities(User $user, Builder $query): Builder
    {
        $ids = $this->facilityIds($user);

        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    /**
     * Narrow a store query to what the user may act in.
     *
     * @param  Builder<Warehouse>  $query
     * @return Builder<Warehouse>
     */
    public function scopeStores(User $user, Builder $query): Builder
    {
        if ($this->isCompanyWide($user)) {
            return $query;
        }

        $assignments = $this->assignmentsOf($user);
        $facilityWide = $assignments->whereNull('store_id')->pluck('facility_id')->unique()->all();
        $storeIds = $assignments->whereNotNull('store_id')->pluck('store_id')->unique()->all();

        return $query->where(fn (Builder $q) => $q
            ->whereIn('facility_id', $facilityWide)
            ->orWhereIn('id', $storeIds)
            ->orWhere('is_system', true));
    }

    /**
     * The facilities offered to a user in a picker.
     *
     * @return Collection<int, Facility>
     */
    public function facilitiesFor(User $user): Collection
    {
        return $this->scopeFacilities($user, Facility::query()->active())->ordered()->get();
    }

    /**
     * The facility a user's screens should open on.
     */
    public function primaryFacility(User $user): ?Facility
    {
        $primary = $this->assignmentsOf($user)->firstWhere('is_primary', true) ?? $this->assignmentsOf($user)->first();

        return $primary?->facility;
    }

    public function forget(User $user): void
    {
        unset($this->cache[$user->id]);
    }

    /**
     * @return Collection<int, EmployeeAssignment>
     */
    private function assignmentsOf(User $user): Collection
    {
        return $this->cache[$user->id] ??= EmployeeAssignment::query()
            ->with('facility')
            ->where('user_id', $user->id)
            ->active()
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', now()->toDateString()))
            ->get();
    }
}
