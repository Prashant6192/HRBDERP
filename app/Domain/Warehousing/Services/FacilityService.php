<?php

declare(strict_types=1);

namespace App\Domain\Warehousing\Services;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Facilities: set up with their stores in one go, edited, given more
 * stores later, switched off — never deleted once anything has happened
 * at them.
 */
class FacilityService
{
    public function __construct(
        private readonly StoreService $stores,
        private readonly EmployeeAssignmentService $assignments,
    ) {}

    /**
     * The whole wizard in one transaction: facility, capabilities, stores,
     * people.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $stores
     * @param  list<array{user_id: int, store_id?: int|null, is_primary?: bool, designation?: string|null}>  $employees
     */
    public function create(array $attributes, array $stores, array $employees, ?int $userId): Facility
    {
        return DB::transaction(function () use ($attributes, $stores, $employees, $userId): Facility {
            $type = FacilityType::query()->active()->findOrFail($attributes['facility_type_id']);

            $facility = Facility::create([
                ...$this->details($attributes),
                'code' => $this->uniqueCode($attributes['code'] ?? null, $attributes['name'], $attributes['city'] ?? null),
                'facility_type_id' => $type->id,
                ...$this->capabilities($attributes, $type->default_capabilities ?? []),
                'opening_stock_enabled' => (bool) ($attributes['opening_stock_enabled'] ?? true),
                'is_active' => (bool) ($attributes['is_active'] ?? true),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->addStores($facility, $stores, $userId);

            foreach ($employees as $employee) {
                $user = User::query()->findOrFail($employee['user_id']);
                $store = isset($employee['store_id']) && $employee['store_id'] ? Warehouse::query()->findOrFail($employee['store_id']) : null;

                $this->assignments->assign($user, $facility, $store, [
                    'is_primary' => (bool) ($employee['is_primary'] ?? false),
                    'designation' => $employee['designation'] ?? null,
                ], $userId);
            }

            return $facility->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Facility $facility, array $attributes, ?int $userId): Facility
    {
        return DB::transaction(function () use ($facility, $attributes, $userId): Facility {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);

            $details = $this->details($attributes);

            if (isset($attributes['facility_type_id'])) {
                $details['facility_type_id'] = FacilityType::query()->findOrFail($attributes['facility_type_id'])->id;
            }

            if (isset($attributes['code']) && Str::upper(trim($attributes['code'])) !== $facility->code) {
                $details['code'] = $this->uniqueCode($attributes['code'], $attributes['name'] ?? $facility->name, $attributes['city'] ?? $facility->city);
            }

            $capabilities = [];

            foreach (FacilityCapability::cases() as $capability) {
                if (array_key_exists($capability->value, $attributes)) {
                    $capabilities[$capability->value] = (bool) $attributes[$capability->value];
                }
            }

            if (($capabilities['can_manufacture'] ?? true) === false && $facility->can_manufacture) {
                $this->assertNoOpenProduction($facility);
            }

            if (array_key_exists('opening_stock_enabled', $attributes)) {
                $details['opening_stock_enabled'] = (bool) $attributes['opening_stock_enabled'];
            }

            if (array_key_exists('is_active', $attributes)) {
                if (! $attributes['is_active'] && $facility->is_active) {
                    $this->assertCanDeactivate($facility);
                }

                $details['is_active'] = (bool) $attributes['is_active'];
            }

            $facility->fill([...$details, ...$capabilities, 'updated_by' => $userId])->save();

            return $facility->refresh();
        });
    }

    /**
     * Give an existing facility more stores. Nothing already there changes.
     *
     * @param  list<array<string, mixed>>  $stores
     * @return list<Warehouse>
     */
    public function addStores(Facility $facility, array $stores, ?int $userId): array
    {
        $created = [];

        foreach ($stores as $definition) {
            if (empty($definition['store_category_id'])) {
                continue;
            }

            $created[] = $this->stores->create($facility, $definition, $userId);
        }

        return $created;
    }

    public function deactivate(Facility $facility, ?int $userId): Facility
    {
        return DB::transaction(function () use ($facility, $userId): Facility {
            $facility = Facility::query()->lockForUpdate()->findOrFail($facility->id);
            $this->assertCanDeactivate($facility);

            $facility->fill(['is_active' => false, 'updated_by' => $userId])->save();
            $facility->stores()->update(['is_active' => false]);

            return $facility;
        });
    }

    public function activate(Facility $facility, ?int $userId): Facility
    {
        $facility->fill(['is_active' => true, 'updated_by' => $userId])->save();

        return $facility;
    }

    /**
     * Once a facility is live its opening stock screen is switched off so
     * nobody books "opening" stock on top of running balances.
     */
    public function setOpeningStock(Facility $facility, bool $enabled, ?int $userId): Facility
    {
        $facility->fill(['opening_stock_enabled' => $enabled, 'updated_by' => $userId])->save();

        return $facility;
    }

    public function assertCanDeactivate(Facility $facility): void
    {
        $storeIds = $facility->stores()->pluck('id');

        if (StockBalance::query()->whereIn('warehouse_id', $storeIds)->where('on_hand', '>', 0)->exists()) {
            throw new FacilityException("{$facility->name} still holds stock in its stores. Transfer it out before deactivating the facility.");
        }

        $this->assertNoOpenProduction($facility);

        if (StockTransfer::query()->forFacility($facility->id)->open()->exists()) {
            throw new FacilityException("{$facility->name} has open stock transfers. Receive or cancel them first.");
        }
    }

    private function assertNoOpenProduction(Facility $facility): void
    {
        if (ManufacturingOrder::query()->where('facility_id', $facility->id)->open()->exists()) {
            throw new FacilityException("{$facility->name} has manufacturing orders in progress. Complete or cancel them before switching manufacturing off.");
        }
    }

    /**
     * FAC-RDP-001, FAC-DEL-001, FAC-DEL-002…
     */
    public function uniqueCode(?string $requested, string $name, ?string $city): string
    {
        $requested = Str::upper(trim((string) $requested));

        if ($requested !== '') {
            if (Facility::withTrashed()->where('code', $requested)->exists()) {
                throw new FacilityException("A facility with the code {$requested} already exists.");
            }

            return $requested;
        }

        $source = $city ?: $name;
        $stem = substr(preg_replace('/[^A-Z]/', '', Str::upper($source)) ?: 'FAC', 0, 3);
        $n = 0;

        do {
            $code = sprintf('FAC-%s-%03d', $stem, ++$n);
        } while (Facility::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function details(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip([
            'name', 'manager_id', 'address_line_1', 'address_line_2', 'city', 'state', 'pincode', 'country',
            'phone', 'email', 'gstin', 'notes',
        ]));
    }

    /**
     * Explicit switches win; otherwise the type's defaults.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, bool>  $defaults
     * @return array<string, bool>
     */
    private function capabilities(array $attributes, array $defaults): array
    {
        $result = [];

        foreach (FacilityCapability::cases() as $capability) {
            $result[$capability->value] = array_key_exists($capability->value, $attributes)
                ? (bool) $attributes[$capability->value]
                : (bool) ($defaults[$capability->value] ?? false);
        }

        return $result;
    }
}
