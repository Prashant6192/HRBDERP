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
            $plan = ProductionPlan::query()->lockForUpdate()->with('lines')->findOrFail($plan->getKey());

            if (! in_array($plan->status, [ProductionPlanStatus::Checked, ProductionPlanStatus::Requested], strict: true)) {
                throw new ManufacturingException("{$plan->number} is {$plan->status->label()}; only a checked plan can go to manufacturing.");
            }

            if ($plan->lines->isEmpty()) {
                throw new ManufacturingException("{$plan->number} has no requirement lines.");
            }

            if (ManufacturingOrder::query()->where('production_plan_id', $plan->id)->open()->exists()) {
                throw new ManufacturingException("{$plan->number} already has an open manufacturing order.");
            }

            $order = ManufacturingOrder::create([
                'number' => $this->sequences->nextNumber('MO', now()->format('ym')),
                'production_plan_id' => $plan->id,
                'formula_id' => $plan->formula_id,
                'formula_version_id' => $plan->formula_version_id,
                'product_id' => $plan->product_id,
                'planned_quantity' => $plan->planned_quantity,
                'planned_uom_id' => $plan->planned_uom_id,
                'planned_units' => $plan->planned_units,
                'status' => ManufacturingOrderStatus::Draft,
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
            $order = ManufacturingOrder::query()->lockForUpdate()->with('lines.item.stockUom')->findOrFail($order->getKey());

            if ($order->status !== ManufacturingOrderStatus::Draft) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()} and cannot be approved.");
            }

            $lines = $order->lines->filter(fn (ManufacturingOrderLine $line) => ! $line->as_required && $line->plannedQuantity()->isPositive());

            // Look before holding, so the message names every shortfall at
            // once instead of stopping at the first.
            $short = [];

            foreach ($lines as $line) {
                $store = $this->storeFor($line->store_kind);
                $free = $this->balances->availableForProduction($line->item, [$store->id]);

                if ($free->isLessThan($line->plannedQuantity())) {
                    $short[] = sprintf(
                        '%s (%s %s needed, %s free)',
                        $line->item->name,
                        $line->plannedQuantity()->strippedOfTrailingZeros(),
                        $line->item->stockUom->code,
                        $free->strippedOfTrailingZeros(),
                    );
                }
            }

            if ($short !== []) {
                throw new ManufacturingException($this->shortageMessage($short));
            }

            foreach ($lines as $line) {
                /** @var ManufacturingOrderLine $line */
                $store = $this->storeFor($line->store_kind);

                try {
                    $held = $this->reservations->reserve($order, $line->item, $store, $line->plannedQuantity(), $userId, "Manufacturing order {$order->number}");
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

            $this->consumeHeld($order, StoreKind::RawMaterial, $userId);

            $order->fill([
                'status' => ManufacturingOrderStatus::InProgress,
                'started_by' => $userId,
                'started_at' => now(),
            ])->save();

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
            $order = ManufacturingOrder::query()->lockForUpdate()->with(['lines', 'product.stockUom', 'plannedUom', 'plan'])->findOrFail($order->getKey());

            if ($order->status !== ManufacturingOrderStatus::InProgress) {
                throw new ManufacturingException("{$order->number} is {$order->status->label()}; only an order in progress can be completed.");
            }

            $outputQuantity = BigDecimal::of($output['output_quantity']);

            if (! $outputQuantity->isPositive()) {
                throw new ManufacturingException('The output quantity must be greater than zero.');
            }

            $units = isset($output['output_units']) && $output['output_units'] !== null && $output['output_units'] !== '' ? (int) $output['output_units'] : null;
            $manufacturedAt = isset($output['manufactured_at']) && $output['manufactured_at'] ? CarbonImmutable::parse($output['manufactured_at']) : CarbonImmutable::today();

            $this->consumeHeld($order, StoreKind::Packaging, $userId);
            $this->reservations->releaseAllFor($order);

            $lot = null;

            if ($order->product !== null) {
                $lot = $this->postOutput($order, $outputQuantity, $units, $manufacturedAt, $output['expiry_at'] ?? null, $userId);
            }

            $yield = $outputQuantity->dividedBy($order->plannedQuantity(), 6, RoundingMode::HalfUp)
                ->multipliedBy(100)->toScale(3, RoundingMode::HalfUp);

            $order->fill([
                'status' => ManufacturingOrderStatus::Completed,
                'output_quantity' => $outputQuantity->__toString(),
                'output_units' => $units,
                'yield_percentage' => $yield->__toString(),
                'output_lot_id' => $lot?->id,
                'manufactured_at' => $manufacturedAt->toDateString(),
                'notes' => isset($output['notes']) && $output['notes'] !== null && $output['notes'] !== '' ? trim(($order->notes ?? '')."\n".$output['notes']) : $order->notes,
                'completed_by' => $userId,
                'completed_at' => now(),
            ])->save();

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

        $lot = InventoryLot::create([
            'item_id' => $product->id,
            'batch_number' => $this->batchNumbers->generate($product, $manufacturedAt),
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

        $fgStore = $this->warehouses->finishedGoodsStore();
        $target = $needsQc ? $this->warehouses->quarantine() : $fgStore;

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
            'Quantity not available for %d material%s: %s%s. Raise or receive the material requests first.',
            $count,
            $count === 1 ? '' : 's',
            implode('; ', $shown),
            $rest > 0 ? "; and {$rest} more" : '',
        );
    }

    private function storeFor(StoreKind $kind)
    {
        return match ($kind) {
            StoreKind::RawMaterial => $this->warehouses->rawMaterialStore(),
            StoreKind::Packaging => $this->warehouses->packagingStore(),
        };
    }
}
