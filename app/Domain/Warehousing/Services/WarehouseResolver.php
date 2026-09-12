<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * Finds the stores a workflow relies on by role — and, when told which
 * facility the work happens at, only among that facility's stores.
 *
 * Nothing here knows a facility by name. "The manufacturing facility" is
 * whichever active facility has manufacturing switched on; if there are
 * several, the caller must say which.
 */
class WarehouseResolver
{
    /**
     * Where received stock waits for quality.
     */
    public function quarantine(?Facility $facility = null): Warehouse
    {
        $warehouse = $this->baseQuery($facility)
            ->where('type', WarehouseType::Quarantine->value)
            ->first();

        if ($warehouse === null) {
            throw new RuntimeException(
                $this->missing('quarantine', WarehouseType::Quarantine, $facility)
            );
        }

        return $warehouse;
    }

    /**
     * The store production draws raw materials from.
     */
    public function rawMaterialStore(?Facility $facility = null): Warehouse
    {
        return $this->storeOfType(WarehouseType::RawMaterial, 'raw material', $facility);
    }

    /**
     * The store packaging is picked from.
     */
    public function packagingStore(?Facility $facility = null): Warehouse
    {
        return $this->storeOfType(WarehouseType::Packaging, 'packaging', $facility);
    }

    /**
     * Where finished batches go.
     */
    public function finishedGoodsStore(?Facility $facility = null): Warehouse
    {
        return $this->storeOfType(WarehouseType::FinishedGoods, 'finished goods', $facility);
    }

    public function findStoreOfType(WarehouseType $type, ?Facility $facility = null): ?Warehouse
    {
        return $this->baseQuery($facility)
            ->where('is_quarantine', false)
            ->where('type', $type->value)
            ->first();
    }

    /**
     * The system's in-transit position: stock that has left one facility
     * and not yet reached the next.
     */
    public function inTransit(): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('is_system', true)
            ->where('type', WarehouseType::InTransit->value)
            ->orderBy('id')
            ->first();

        // Created by the migration; recreated here should it ever be missing.
        return $warehouse ?? ReferenceDataSeeder::ensureTransitStore();
    }

    /**
     * The facility production happens at when the caller has not chosen one:
     * the only active facility that can manufacture. With several, the
     * screens must ask.
     */
    public function defaultManufacturingFacility(): ?Facility
    {
        return Facility::query()->active()->manufacturing()->ordered()->first();
    }

    /**
     * Every active facility able to do something.
     *
     * @return Collection<int, Facility>
     */
    public function facilitiesWith(FacilityCapability $capability)
    {
        return Facility::query()->active()->withCapability($capability)->ordered()->get();
    }

    /**
     * @return Builder<Warehouse>
     */
    private function baseQuery(?Facility $facility): Builder
    {
        return Warehouse::query()
            ->where('is_active', true)
            ->where('is_system', false)
            ->atFacility($facility)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    private function storeOfType(WarehouseType $type, string $label, ?Facility $facility): Warehouse
    {
        $warehouse = $this->findStoreOfType($type, $facility);

        if ($warehouse === null) {
            throw new RuntimeException($this->missing($label, $type, $facility));
        }

        return $warehouse;
    }

    private function missing(string $label, WarehouseType $type, ?Facility $facility): string
    {
        $where = $facility === null ? '' : " at {$facility->name}";

        return "No active {$label} store is configured{$where}. Add one under Facilities with the store category \"{$type->label()}\".";
    }
}
