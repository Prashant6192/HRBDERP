<?php

declare(strict_types=1);

namespace App\Domain\Planning\Services;

use App\Domain\Formulation\Models\Formula;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\DTOs\RequirementLine;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The life of a production plan: raised, checked against the stores,
 * turned into material requests, cancelled.
 */
class ProductionPlanService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly ProductionRequirementService $requirements,
        private readonly WarehouseResolver $warehouses,
    ) {}

    /**
     * Raise a plan and check it straight away.
     *
     * @param  array{formula_id: int, quantity: string, uom_id: int, facility_id?: int|null, planned_start_date?: string|null, notes?: string|null}  $attributes
     */
    public function create(array $attributes, ?int $userId): ProductionPlan
    {
        return DB::transaction(function () use ($attributes, $userId): ProductionPlan {
            $facility = $this->manufacturingFacility($attributes['facility_id'] ?? null);
            $formula = Formula::query()->with('activeVersion', 'product')->findOrFail($attributes['formula_id']);

            if ($formula->isArchived()) {
                throw new PlanningException("{$formula->name} is archived and cannot be planned.");
            }

            if ($formula->activeVersion === null) {
                throw new PlanningException("{$formula->name} has no active version. Activate a recipe before planning a batch from it.");
            }

            $uom = Uom::findOrFail($attributes['uom_id']);

            $plan = ProductionPlan::create([
                'number' => $this->sequences->nextNumber('PLN', now()->format('ym')),
                'facility_id' => $facility->id,
                'formula_id' => $formula->id,
                'formula_version_id' => $formula->activeVersion->id,
                'product_id' => $formula->product_id,
                'planned_quantity' => $attributes['quantity'],
                'planned_uom_id' => $uom->id,
                'status' => ProductionPlanStatus::Draft,
                'planned_start_date' => $attributes['planned_start_date'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $userId,
            ]);

            return $this->check($plan);
        });
    }

    /**
     * Work the requirement out (again) against what the stores hold now.
     */
    public function check(ProductionPlan $plan): ProductionPlan
    {
        return DB::transaction(function () use ($plan): ProductionPlan {
            $plan = ProductionPlan::query()->lockForUpdate()->with(['formulaVersion', 'plannedUom', 'product', 'facility'])->findOrFail($plan->getKey());

            if (! $plan->status->canBeChecked()) {
                throw new PlanningException("{$plan->number} is {$plan->status->label()} and cannot be re-checked.");
            }

            // A plan raised before facilities existed is filed under the
            // manufacturing facility the first time it is looked at again.
            if ($plan->facility === null) {
                $plan->facility()->associate($this->manufacturingFacility(null));
            }

            $result = $this->requirements->calculate($plan->formulaVersion, $plan->planned_quantity, $plan->plannedUom, $plan->product, $plan->facility);

            $plan->lines()->delete();

            $lineNo = 0;

            foreach ($result->lines() as $line) {
                /** @var RequirementLine $line */
                $plan->lines()->create([
                    'line_no' => ++$lineNo,
                    'store_kind' => $line->storeKind,
                    'item_id' => $line->itemId,
                    'uom_id' => $line->uomId,
                    'percentage' => $line->percentage?->__toString(),
                    'is_qs' => $line->isQs,
                    'as_required' => $line->asRequired,
                    'required_quantity' => $line->required->__toString(),
                    'available_quantity' => $line->available->__toString(),
                    'shortage_quantity' => $line->shortage->__toString(),
                    'restock_quantity' => $line->restock->__toString(),
                    'level_now' => $line->levelNow,
                    'level_after' => $line->levelAfter,
                    'notes' => $line->notes === [] ? null : $line->notes,
                    'available_elsewhere' => $line->availableElsewhere === [] ? null : $line->availableElsewhere,
                ]);
            }

            $plan->fill([
                'planned_units' => $result->units,
                'warnings' => $result->warnings === [] ? null : $result->warnings,
                'checked_at' => now(),
                'status' => $plan->status === ProductionPlanStatus::Draft ? ProductionPlanStatus::Checked : $plan->status,
            ])->save();

            return $plan->refresh();
        });
    }

    /**
     * Raise one Production Material Request per store that has lines.
     *
     * @return Collection<int, MaterialRequest>
     */
    public function generateRequests(ProductionPlan $plan, ?int $userId, ?string $neededBy = null): Collection
    {
        return DB::transaction(function () use ($plan, $userId, $neededBy): Collection {
            $plan = ProductionPlan::query()->lockForUpdate()->with(['lines.item', 'facility'])->findOrFail($plan->getKey());

            if ($plan->status !== ProductionPlanStatus::Checked) {
                throw new PlanningException(match ($plan->status) {
                    ProductionPlanStatus::Requested => "{$plan->number} already has its material requests.",
                    ProductionPlanStatus::Draft => "{$plan->number} has not been checked yet.",
                    default => "{$plan->number} is {$plan->status->label()}.",
                });
            }

            if ($plan->lines->isEmpty()) {
                throw new PlanningException("{$plan->number} has no requirement lines to request.");
            }

            $requests = new Collection;

            foreach (StoreKind::cases() as $kind) {
                $lines = $plan->lines->where('store_kind', $kind)->filter(fn ($line) => ! $line->as_required)->values();

                if ($lines->isEmpty()) {
                    continue;
                }

                $store = $this->warehouses->findStoreOfType($kind->warehouseType(), $plan->facility);

                if ($store === null) {
                    $where = $plan->facility?->name ?? 'the manufacturing facility';
                    throw new PlanningException("No active {$kind->label()} is configured at {$where}; add one on the facility screen before raising requests.");
                }

                $request = MaterialRequest::create([
                    'number' => $this->sequences->nextNumber('PMR', now()->format('ym')),
                    'production_plan_id' => $plan->id,
                    'store_kind' => $kind,
                    'warehouse_id' => $store->id,
                    'status' => MaterialRequestStatus::Open,
                    'needed_by' => $neededBy ?? $plan->planned_start_date?->toDateString(),
                    'requested_by' => $userId,
                    'requested_at' => now(),
                ]);

                foreach ($lines as $index => $line) {
                    $request->lines()->create([
                        'line_no' => $index + 1,
                        'item_id' => $line->item_id,
                        'uom_id' => $line->uom_id,
                        'required_quantity' => $line->required_quantity,
                        'available_quantity' => $line->available_quantity,
                        'quantity_to_order' => $line->shortage_quantity,
                        'restock_quantity' => $line->restock_quantity,
                        'alert_level' => $line->level_now,
                    ]);
                }

                $requests->push($request);
            }

            $plan->fill(['status' => ProductionPlanStatus::Requested, 'requested_at' => now()])->save();

            return $requests;
        });
    }

    /**
     * The facility a batch will be made at: the one asked for, provided it
     * can manufacture, or the company's default manufacturing facility.
     *
     * @throws PlanningException
     */
    public function manufacturingFacility(?int $facilityId): Facility
    {
        if ($facilityId !== null) {
            $facility = Facility::query()->find($facilityId);

            if ($facility === null) {
                throw new PlanningException('Choose a facility for the batch.');
            }

            if (! $facility->is_active) {
                throw new PlanningException("{$facility->name} is deactivated and cannot run production.");
            }

            if (! $facility->can(FacilityCapability::Manufacture)) {
                throw new PlanningException("{$facility->name} cannot run production: manufacturing is not enabled for it. Choose a manufacturing facility.");
            }

            return $facility;
        }

        $facility = $this->warehouses->defaultManufacturingFacility();

        if ($facility === null) {
            throw new PlanningException('No facility has manufacturing enabled. Enable it on a facility under Facilities & Warehouses before planning a batch.');
        }

        return $facility;
    }

    public function cancel(ProductionPlan $plan, ?int $userId, ?string $reason = null): ProductionPlan
    {
        return DB::transaction(function () use ($plan, $reason): ProductionPlan {
            $plan = ProductionPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            if (! $plan->status->isOpen()) {
                throw new PlanningException("{$plan->number} is {$plan->status->label()} and cannot be cancelled.");
            }

            $plan->materialRequests()->open()->get()->each(function (MaterialRequest $request): void {
                $request->fill(['status' => MaterialRequestStatus::Cancelled, 'cancelled_at' => now()])->save();
            });

            $plan->fill([
                'status' => ProductionPlanStatus::Cancelled,
                'cancelled_at' => now(),
                'notes' => $reason === null ? $plan->notes : trim(($plan->notes ?? '')."\nCancelled: {$reason}"),
            ])->save();

            return $plan;
        });
    }
}
