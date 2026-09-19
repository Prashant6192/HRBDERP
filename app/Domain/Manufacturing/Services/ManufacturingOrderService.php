<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Services;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Services\BatchNumberGenerator;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\InventoryReservationService;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Enums\ProductionStage;
use App\Domain\Manufacturing\Exceptions\ManufacturingException;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\Measurement\Enums\UomDimension;
use App\Domain\Measurement\Exceptions\IncompatibleUnitsException;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Enums\StoreKind;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Models\ProductionPlanLine;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Making a batch.
 *
 *  draft ──approve──▶ approved ──start──▶ in progress ──complete──▶ completed
 *                     (materials held)    (raw materials used)     (packaging used, batch posted)
 *
 * Every stock movement goes through the ledger and the reservation service,
 * so a batch can never consume what the store did not have, and two orders
 * cannot hold the same drum.
 */
class ManufacturingOrderService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly InventoryReservationService $reservations,
        private readonly InventoryLedgerService $ledger,
        private readonly StockBalanceService $balances,
        private readonly BatchNumberGenerator $batchNumbers,
        private readonly UnitConversionService $conversions,
        private readonly WarehouseResolver $warehouses,
    ) {}

    /**
     * Open an order for a checked plan, taking its requirement as the
     * material list.
     */
    public function createFromPlan(ProductionPlan $plan, ?int $userId, ?string $notes = null): ManufacturingOrder
    {
        return DB::transaction(function () use ($plan, $userId, $notes): ManufacturingOrder {
            $plan = ProductionPlan::query()->lockForUpdate()->with(['lines', 'facility'])->findOrFail($plan->getKey());

            if (! in_array($plan->status, [ProductionPlanStatus::Checked, ProductionPlanStatus::Requested], strict: true)) {
                throw new ManufacturingException("{$plan->number} is {$plan->status->label()}; only a checked plan can go to manufacturing.");
            }

            if ($plan->lines->isEmpty()) {
                throw new ManufacturingException("{$plan->number} has no requirement lines.");
            }

            if (ManufacturingOrder::query()->where('production_plan_id', $plan->id)->open()->exists()) {
                throw new ManufacturingException("{$plan->number} already has an open manufacturing order.");
            }

            $facility = $this->manufacturingFacility($plan->facility);

            $order = ManufacturingOrder::create([
                'number' => $this->sequences->nextNumber('MO', now()->format('ym')),
                'facility_id' => $facility->id,
                'production_plan_id' => $plan->id,
                'formula_id' => $plan->formula_id,
                'formula_version_id' => $plan->formula_version_id,
                'product_id' => $plan->product_id,
                'planned_quantity' => $plan->planned_quantity,
                'planned_uom_id' => $plan->planned_uom_id,
                'planned_units' => $plan->planned_units,
                'status' => ManufacturingOrderStatus::Draft,
                // Whose batch, against which PO, and whose material.
                'manufacturing_type' => $plan->manufacturing_type,
                'client_id' => $plan->client_id,
                'client_po_ref' => $plan->client_po_ref,
                'required_delivery_at' => $plan->required_delivery_at?->toDateString(),
                'material_source' => $plan->material_source,
                'client_supplied_item_ids' => $plan->client_supplied_item_ids,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($plan->lines as $line) {
                /** @var ProductionPlanLine $line */
                $order->lines()->create([
                    'line_no' => $line->line_no,
                    'store_kind' => $line->store_kind,
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'percentage' => $line->percentage,
                    'is_qs' => $line->is_qs,
                    'as_required' => $line->as_required,
                    'planned_quantity' => $line->required_quantity,
                ]);
            }

            return $order->refresh();
        });
    }

    /**
     * Hold every material in its store. All or nothing: if one material is
     * short, nothing is reserved and the message says what is missing.
     */
    public function approve(ManufacturingOrder $order, int $userId): ManufacturingOrder
    {
        return DB::transaction(function () use ($order, $userId): ManufacturingOrder {
            $order = ManufacturingOrder::query()->lockForUpdate()->with(['lines.item.stockUom', 'facility'])->findOrFail($order->getKey());

            if ($order->status !== ManufacturingOrderStatus::Draft) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()} and cannot be approved.");
            }

            $facility = $this->manufacturingFacility($order->facility);

            $lines = $order->lines->filter(fn (ManufacturingOrderLine $line) => ! $line->as_required && $line->plannedQuantity()->isPositive());

            // Look before holding, so the message names every shortfall at
            // once instead of stopping at the first.
            $short = [];

            foreach ($lines as $line) {
                $store = $this->storeFor($line->store_kind, $facility);
                // A client's material is drawn from the client's own batches
                // alone; everything else from the company's.
                $owner = $order->ownerForItem($line->item_id);
                $free = $this->balances->availableForProduction($line->item, [$store->id], $owner);

                if ($free->isLessThan($line->plannedQuantity())) {
                    $short[] = sprintf(
                        '%s (%s %s needed, %s free%s)',
                        $line->item->name,
                        $line->plannedQuantity()->strippedOfTrailingZeros(),
                        $line->item->stockUom->code,
                        $free->strippedOfTrailingZeros(),
                        $owner !== null ? ' — client supplied, awaiting client material' : '',
                    );
                }
            }

            if ($short !== []) {
                throw new ManufacturingException($this->shortageMessage($short));
            }

            foreach ($lines as $line) {
                /** @var ManufacturingOrderLine $line */
                $store = $this->storeFor($line->store_kind, $facility);

                try {
                    $held = $this->reservations->reserve($order, $line->item, $store, $line->plannedQuantity(), $userId, "Manufacturing order {$order->number}", null, $order->ownerForItem($line->item_id));
                } catch (InsufficientStockException $e) {
                    // Someone else took it between the look and the hold.
                    throw new ManufacturingException("Quantity not available: {$line->item->name} was taken by another order a moment ago. Try again.");
                }

                $line->forceFill([
                    'reserved_quantity' => $held
                        ->reduce(fn (BigDecimal $carry, StockReservation $r) => $carry->plus($r->quantity), BigDecimal::zero())
                        ->__toString(),
                ])->save();
            }

            $order->fill([
                'status' => ManufacturingOrderStatus::Approved,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            $order->plan?->fill(['status' => ProductionPlanStatus::InProduction])->save();

            return $order->refresh();
        });
    }

    /**
     * Issue the raw materials to the kettle: every held raw material is
     * consumed through the ledger.
     */
    public function start(ManufacturingOrder $order, ?int $userId): ManufacturingOrder
    {
        return DB::transaction(function () use ($order, $userId): ManufacturingOrder {
            $order = ManufacturingOrder::query()->lockForUpdate()->with('lines')->findOrFail($order->getKey());

            if ($order->status !== ManufacturingOrderStatus::Approved) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()}; only an approved order can be started.");
            }

            // Scan before issue: when the floor is set to verify every drum,
            // a batch does not start until every raw material has passed.
            if (config('erp.shop_floor.require_scan_before_start', false)) {
                $status = app(IssueVerificationService::class)->status($order, StoreKind::RawMaterial);

                if (! $status['complete']) {
                    $missing = collect($status['lines'])->where('verified', false)->pluck('name')->implode(', ');

                    throw new ManufacturingException("Scan every raw material against {$order->number} before starting it. Not yet verified: {$missing}.");
                }
            }

            $this->consumeHeld($order, StoreKind::RawMaterial, $userId);

            $order->fill([
                'status' => ManufacturingOrderStatus::InProgress,
                'current_stage' => ProductionStage::Weighing,
                'stage_progress' => 0,
                'stage_updated_at' => now(),
                'started_by' => $userId,
                'started_at' => now(),
            ])->save();

            $order->stageEvents()->create(['stage' => ProductionStage::Weighing, 'progress' => 0, 'note' => 'Batch started.', 'recorded_by' => $userId, 'recorded_at' => now()]);

            return $order->refresh();
        });
    }

    /**
     * The batch is made and packed: packaging is consumed, anything still
     * held is released, and the finished goods are posted as a new lot —
     * into quarantine with an inspection if the product needs QC.
     *
     * @param  array{output_quantity: string, output_units?: int|null, manufactured_at?: string|null, expiry_at?: string|null, notes?: string|null}  $output
     */
    public function complete(ManufacturingOrder $order, ?int $userId, array $output): ManufacturingOrder
    {
        return DB::transaction(function () use ($order, $userId, $output): ManufacturingOrder {
            $order = ManufacturingOrder::query()->lockForUpdate()->with(['lines', 'product.stockUom', 'plannedUom', 'plan', 'facility', 'client'])->findOrFail($order->getKey());

            if ($order->status !== ManufacturingOrderStatus::InProgress) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()}; only an order in progress can be completed.");
            }

            $outputQuantity = BigDecimal::of($output['output_quantity']);

            if (! $outputQuantity->isPositive()) {
                throw new ManufacturingException('The output quantity must be greater than zero.');
            }

            $manufacturedAt = isset($output['manufactured_at']) && $output['manufactured_at'] ? CarbonImmutable::parse($output['manufactured_at']) : CarbonImmutable::today();
            $reconciled = $this->reconcile($order, $outputQuantity, $output);
            $units = $reconciled['output_units'];

            $this->consumeHeld($order, StoreKind::Packaging, $userId);
            $this->reservations->releaseAllFor($order);

            $lot = null;

            if ($order->product !== null) {
                $lot = $this->postOutput($order, $outputQuantity, $units, $manufacturedAt, $output['expiry_at'] ?? null, $userId);
            }

            $order->fill([
                'status' => ManufacturingOrderStatus::Completed,
                'current_stage' => ProductionStage::Completed,
                'stage_progress' => 100,
                'stage_updated_at' => now(),
                'output_quantity' => $outputQuantity->__toString(),
                ...$reconciled,
                'output_lot_id' => $lot?->id,
                'manufactured_at' => $manufacturedAt->toDateString(),
                'notes' => isset($output['notes']) && $output['notes'] !== null && $output['notes'] !== '' ? trim(($order->notes ?? '')."\n".$output['notes']) : $order->notes,
                'completed_by' => $userId,
                'completed_at' => now(),
            ])->save();

            $order->stageEvents()->create(['stage' => ProductionStage::Completed, 'progress' => 100, 'note' => 'Batch completed.', 'recorded_by' => $userId, 'recorded_at' => now()]);
            $order->plan?->fill(['status' => ProductionPlanStatus::Completed])->save();

            return $order->refresh();
        });
    }

    /**
     * Let go of whatever is still held. Materials already consumed stay
     * consumed — the ledger does not un-happen a kettle.
     */
    public function cancel(ManufacturingOrder $order, ?int $userId, ?string $reason = null): ManufacturingOrder
    {
        return DB::transaction(function () use ($order, $reason): ManufacturingOrder {
            $order = ManufacturingOrder::query()->lockForUpdate()->with('plan')->findOrFail($order->getKey());

            if (! $order->status->isOpen()) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()} and cannot be cancelled.");
            }

            $this->reservations->releaseAllFor($order);

            $order->fill([
                'status' => ManufacturingOrderStatus::Cancelled,
                'cancelled_at' => now(),
                'notes' => $reason === null || $reason === '' ? $order->notes : trim(($order->notes ?? '')."\nCancelled: {$reason}"),
            ])->save();

            if ($order->plan !== null && $order->plan->status === ProductionPlanStatus::InProduction) {
                $order->plan->fill([
                    'status' => $order->plan->materialRequests()->exists() ? ProductionPlanStatus::Requested : ProductionPlanStatus::Checked,
                ])->save();
            }

            return $order->refresh();
        });
    }

    /**
     * The batch account, the way a manufacturing head reads it.
     *
     * Three yields, each answering a different question:
     *  - bulk yield: what the kettle gave against what was planned
     *    (500 kg planned, 495 kg made = 99.0%);
     *  - packing yield: what the line kept against what it filled
     *    (4,950 filled, 99 rejected = 98.0%);
     *  - overall yield: good units against the units the batch was
     *    planned for (4,851 of 5,000 = 97.0%).
     *
     * Good units = filled − rejected − samples, and those are the units
     * that go to stock. Rejects and samples are on the record; they never
     * reach the finished goods store.
     *
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public function reconcile(ManufacturingOrder $order, BigDecimal $outputQuantity, array $output): array
    {
        $int = static fn (string $key): ?int => isset($output[$key]) && $output[$key] !== null && $output[$key] !== '' ? (int) $output[$key] : null;

        $filled = $int('filled_units');
        $rejected = $int('rejected_units');
        $samples = $int('sample_units');
        $good = $int('output_units');

        if ($filled !== null) {
            $rejected ??= 0;
            $samples ??= 0;

            if ($rejected + $samples > $filled) {
                throw new ManufacturingException("Rejected ({$rejected}) and sample ({$samples}) units cannot exceed the {$filled} units filled.");
            }

            $good = $filled - $rejected - $samples;
        } elseif ($rejected !== null || $samples !== null) {
            // Rejects without a filled count: good units were keyed, so
            // the fill was good + rejected + samples.
            if ($good === null) {
                throw new ManufacturingException('Enter the units filled, or the good units, to record rejects and samples.');
            }

            $rejected ??= 0;
            $samples ??= 0;
            $filled = $good + $rejected + $samples;
        }

        if ($good !== null && $good < 1) {
            throw new ManufacturingException('No good units are left to post: every filled unit is a reject or a sample.');
        }

        $leftover = isset($output['bulk_leftover_quantity']) && $output['bulk_leftover_quantity'] !== null && $output['bulk_leftover_quantity'] !== ''
            ? BigDecimal::of($output['bulk_leftover_quantity'])
            : null;

        if ($leftover !== null && $leftover->isNegative()) {
            throw new ManufacturingException('Bulk left over cannot be negative.');
        }

        if ($leftover !== null && $leftover->isGreaterThan($outputQuantity)) {
            throw new ManufacturingException('Bulk left over cannot exceed the bulk output.');
        }

        $pct = static fn (BigDecimal|int $part, BigDecimal|int $whole): ?string => BigDecimal::of($whole)->isPositive()
            ? BigDecimal::of($part)->dividedBy(BigDecimal::of($whole), 6, RoundingMode::HalfUp)->multipliedBy(100)->toScale(3, RoundingMode::HalfUp)->__toString()
            : null;

        return [
            'output_units' => $good,
            'filled_units' => $filled,
            'rejected_units' => $filled === null ? null : $rejected,
            'sample_units' => $filled === null ? null : $samples,
            'bulk_leftover_quantity' => $leftover?->__toString(),
            'yield_percentage' => $pct($outputQuantity, $order->plannedQuantity()),
            'packing_yield_percentage' => $filled !== null && $good !== null ? $pct($good, $filled) : null,
            'overall_yield_percentage' => $good !== null && ($order->planned_units ?? 0) > 0 ? $pct($good, (int) $order->planned_units) : null,
            'loss_notes' => isset($output['loss_notes']) && $output['loss_notes'] !== null && trim((string) $output['loss_notes']) !== '' ? trim((string) $output['loss_notes']) : null,
        ];
    }

    // ---- Internals -------------------------------------------------------

    private function consumeHeld(ManufacturingOrder $order, StoreKind $kind, ?int $userId): void
    {
        $lines = $order->lines->where('store_kind', $kind)->keyBy('item_id');

        if ($lines->isEmpty()) {
            return;
        }

        $held = $order->reservations()
            ->active()
            ->whereIn('item_id', $lines->keys()->all())
            ->orderBy('id')
            ->get();

        $consumed = [];

        foreach ($held as $reservation) {
            /** @var StockReservation $reservation */
            $outstanding = $reservation->outstanding();

            if (! $outstanding->isPositive()) {
                continue;
            }

            $this->reservations->consume($reservation, $outstanding, $order, $userId, InventoryTransactionType::ProductionConsumption);
            $consumed[$reservation->item_id] = ($consumed[$reservation->item_id] ?? BigDecimal::zero())->plus($outstanding);
        }

        foreach ($consumed as $itemId => $quantity) {
            $line = $lines->get($itemId);
            $line->forceFill(['consumed_quantity' => $quantity->plus($line->consumed_quantity)->__toString()])->save();
        }
    }

    private function postOutput(ManufacturingOrder $order, BigDecimal $outputQuantity, ?int $units, CarbonImmutable $manufacturedAt, ?string $expiryAt, ?int $userId): InventoryLot
    {
        $product = $order->product;
        $stockUom = $product->stockUom;

        // Finished goods are usually stocked by the piece; a bulk product is
        // stocked in the unit the batch was made in.
        if ($stockUom->dimension === UomDimension::Count) {
            if ($units === null || $units <= 0) {
                throw new ManufacturingException("{$product->name} is stocked in {$stockUom->code}; enter how many units were packed.");
            }

            $ledgerQuantity = BigDecimal::of($units);
        } else {
            $ledgerQuantity = $this->toStockUnit($outputQuantity, $order->plannedUom, $stockUom, $product);
        }

        $expiry = $expiryAt !== null && $expiryAt !== ''
            ? CarbonImmutable::parse($expiryAt)
            : ($product->shelf_life_days ? $manufacturedAt->addDays($product->shelf_life_days) : null);

        $needsQc = (bool) $product->requires_qc;

        // A client's batch is theirs from the moment it exists, and its
        // number says so: TP-001-260913-001.
        $lot = InventoryLot::create([
            'item_id' => $product->id,
            'batch_number' => $this->batchNumbers->generate($product, $manufacturedAt, $order->isThirdParty() ? $order->client?->code : null),
            'owner_client_id' => $order->isThirdParty() ? $order->client_id : null,
            'manufactured_at' => $manufacturedAt->toDateString(),
            'received_at' => now()->toDateString(),
            'expiry_at' => $expiry?->toDateString(),
            'qc_status' => $needsQc ? LotQcStatus::Pending : LotQcStatus::NotRequired,
            'initial_quantity' => $ledgerQuantity->__toString(),
            'source_type' => $order->getMorphClass(),
            'source_id' => $order->id,
            'notes' => "Made on {$order->number}",
            'created_by' => $userId,
        ]);

        $facility = $order->facility;
        $fgStore = $this->warehouses->finishedGoodsStore($facility);
        $target = $needsQc ? $this->warehouses->quarantine($facility) : $fgStore;

        $this->ledger->receive(
            item: $product,
            warehouse: $target,
            quantity: $ledgerQuantity,
            lot: $lot,
            type: InventoryTransactionType::ProductionOutput,
            reference: $order,
            reason: "Output of {$order->number}",
            userId: $userId,
        );

        if ($needsQc) {
            QcInspection::create([
                'number' => $this->sequences->nextNumber('QC', now()->format('ym')),
                'lot_id' => $lot->id,
                'item_id' => $product->id,
                'quantity' => $ledgerQuantity->__toString(),
                'status' => LotQcStatus::Pending,
                'destination_warehouse_id' => $fgStore->id,
                'created_by' => $userId,
            ]);
        }

        return $lot;
    }

    private function toStockUnit(BigDecimal $quantity, Uom $from, Uom $to, $item): BigDecimal
    {
        try {
            return $this->conversions->convert($quantity, $from, $to, $item);
        } catch (IncompatibleUnitsException) {
            $pair = [$from->dimension, $to->dimension];

            if (in_array(UomDimension::Mass, $pair, strict: true) && in_array(UomDimension::Volume, $pair, strict: true) && ! $from->needsItemFactor() && ! $to->needsItemFactor()) {
                return $quantity->multipliedBy($from->factorToBase())->dividedBy($to->factorToBase(), 6, RoundingMode::HalfUp);
            }

            throw new ManufacturingException("Cannot express {$quantity} {$from->code} in {$to->code}, the unit {$item->name} is stocked in.");
        }
    }

    /**
     * @param  list<string>  $short
     */
    private function shortageMessage(array $short): string
    {
        $count = count($short);
        $shown = array_slice($short, 0, 6);
        $rest = $count - count($shown);

        return sprintf(
            'Quantity not available for %d material%s: %s%s. Raise or receive the material requests first; client-supplied material has to arrive from the client.',
            $count,
            $count === 1 ? '' : 's',
            implode('; ', $shown),
            $rest > 0 ? "; and {$rest} more" : '',
        );
    }

    private function storeFor(StoreKind $kind, ?Facility $facility)
    {
        return match ($kind) {
            StoreKind::RawMaterial => $this->warehouses->rawMaterialStore($facility),
            StoreKind::Packaging => $this->warehouses->packagingStore($facility),
        };
    }

    /**
     * An order is only ever made at a facility that can manufacture. A plan
     * without one (raised before facilities existed) falls back to the
     * company's default manufacturing facility.
     */
    private function manufacturingFacility(?Facility $facility): Facility
    {
        $facility ??= $this->warehouses->defaultManufacturingFacility();

        if ($facility === null) {
            throw new ManufacturingException('No facility has manufacturing enabled. Enable it on a facility under Facilities & Warehouses first.');
        }

        if (! $facility->is_active || ! $facility->can(FacilityCapability::Manufacture)) {
            throw new ManufacturingException("Manufacturing orders cannot be raised for {$facility->name}: manufacturing is not enabled there.");
        }

        return $facility;
    }
}
