<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores inside a facility: created from a category, edited, switched off.
 *
 * A store that has ever held stock is never deleted — its ledger lines
 * point at it — so "delete" is refused with a message and "deactivate" is
 * the answer.
 */
class StoreService
{
    public const string CANNOT_DELETE = 'This store cannot be deleted because operational history exists. Deactivate it instead.';

    /**
     * @param  array{store_category_id: int, name?: string|null, code?: string|null, manager_id?: int|null, default_location?: string|null, is_active?: bool, notes?: string|null, sort_order?: int|null}  $attributes
     */
    public function create(Facility $facility, array $attributes, ?int $userId): Warehouse
    {
        return DB::transaction(function () use ($facility, $attributes, $userId): Warehouse {
            $category = StoreCategory::query()->selectable()->findOrFail($attributes['store_category_id']);

            $store = Warehouse::create([
                'code' => $this->uniqueCode($attributes['code'] ?? null, $facility, $category),
                'name' => $attributes['name'] ?: "{$category->name} Store",
                'facility_id' => $facility->id,
                'store_category_id' => $category->id,
                'type' => $category->kind,
                'is_quarantine' => $category->kind->holdsQuarantinedStock(),
                'manager_id' => $attributes['manager_id'] ?? null,
                'address_line_1' => $facility->address_line_1,
                'address_line_2' => $facility->address_line_2,
                'city' => $facility->city,
                'state' => $facility->state,
                'pincode' => $facility->pincode,
                'country' => $facility->country ?: 'India',
                'gstin' => $facility->gstin,
                'is_active' => $attributes['is_active'] ?? true,
                'sort_order' => $attributes['sort_order'] ?? $category->sort_order,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $location = trim((string) ($attributes['default_location'] ?? ''));

            if ($location !== '') {
                $store->locations()->create([
                    'code' => Str::upper(Str::slug($location, '-')),
                    'name' => $location,
                    'type' => 'zone',
                    'is_active' => true,
                ]);
            }

            return $store;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Warehouse $store, array $attributes, ?int $userId): Warehouse
    {
        return DB::transaction(function () use ($store, $attributes, $userId): Warehouse {
            $store = Warehouse::query()->lockForUpdate()->findOrFail($store->id);

            if ($store->is_system) {
                throw new FacilityException("{$store->name} is a system store and cannot be edited.");
            }

            $newCategory = isset($attributes['store_category_id']) ? (int) $attributes['store_category_id'] : $store->store_category_id;

            if ($newCategory !== $store->store_category_id && $store->hasOperationalHistory()) {
                throw new FacilityException("{$store->name} has stock history, so its category cannot change. Create a new store of the other category instead.");
            }

            $store->fill(array_intersect_key($attributes, array_flip([
                'name', 'code', 'store_category_id', 'manager_id', 'is_active', 'notes', 'sort_order',
                'address_line_1', 'address_line_2', 'city', 'state', 'pincode', 'country', 'gstin',
            ])));
            $store->updated_by = $userId;

            if (array_key_exists('is_active', $attributes) && ! $attributes['is_active'] && $store->getOriginal('is_active')) {
                $this->assertCanDeactivate($store);
            }

            $store->save();

            return $store->refresh();
        });
    }

    public function deactivate(Warehouse $store, ?int $userId): Warehouse
    {
        return DB::transaction(function () use ($store, $userId): Warehouse {
            $store = Warehouse::query()->lockForUpdate()->findOrFail($store->id);
            $this->assertCanDeactivate($store);
            $store->fill(['is_active' => false, 'updated_by' => $userId])->save();

            return $store;
        });
    }

    public function activate(Warehouse $store, ?int $userId): Warehouse
    {
        $store->fill(['is_active' => true, 'updated_by' => $userId])->save();

        return $store;
    }

    /**
     * Remove a store that was never used. Anything with history is refused.
     */
    public function delete(Warehouse $store): void
    {
        if ($store->is_system) {
            throw new FacilityException("{$store->name} is a system store and cannot be deleted.");
        }

        if ($store->hasOperationalHistory()) {
            throw new FacilityException(self::CANNOT_DELETE);
        }

        $store->delete();
    }

    /**
     * Whether the store may be switched off: nothing on hand, nothing held.
     */
    public function assertCanDeactivate(Warehouse $store): void
    {
        $onHand = StockBalance::query()->where('warehouse_id', $store->id)->where('on_hand', '>', 0)->exists();

        if ($onHand) {
            throw new FacilityException("{$store->name} still holds stock. Transfer or adjust it out before deactivating the store.");
        }

        $held = StockReservation::query()->where('warehouse_id', $store->id)->where('status', ReservationStatus::Active->value)->exists();

        if ($held) {
            throw new FacilityException("{$store->name} has stock reserved for open orders. Close or cancel them first.");
        }
    }

    /**
     * A store code that is short, readable and unique: RDP-RM, RDP-RM-2…
     */
    public function uniqueCode(?string $requested, Facility $facility, StoreCategory $category): string
    {
        $requested = Str::upper(trim((string) $requested));

        if ($requested !== '') {
            if (Warehouse::withTrashed()->where('code', $requested)->exists()) {
                throw new FacilityException("A store with the code {$requested} already exists.");
            }

            return $requested;
        }

        $stem = $this->facilityStem($facility);
        $base = "{$stem}-{$category->badge}";
        $code = $base;
        $n = 1;

        while (Warehouse::withTrashed()->where('code', $code)->exists()) {
            $code = $base.'-'.(++$n);
        }

        return $code;
    }

    /**
     * FAC-RDP-001 → RDP; anything else → the first letters of the name.
     */
    public function facilityStem(Facility $facility): string
    {
        $parts = explode('-', Str::upper($facility->code));

        if (count($parts) >= 2 && $parts[0] === 'FAC') {
            return $parts[1];
        }

        $letters = preg_replace('/[^A-Z]/', '', Str::upper($facility->name)) ?: 'STR';

        return substr($letters, 0, 3);
    }
}
