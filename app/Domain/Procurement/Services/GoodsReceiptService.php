<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Services;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Services\BatchNumberGenerator;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Inventory\Services\SequenceService;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Measurement\Services\UnitConversionService;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Services\WarehouseResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a delivery note into stock.
 *
 * Creating a receipt records what arrived. Posting it is the moment the
 * stock exists: each line gets a batch number and a lot, the quantity is
 * written to the ledger, and — for anything that needs quality's say-so —
 * it lands in quarantine with an inspection waiting. Material that does not
 * need QC goes straight to the store the receipt names.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly BatchNumberGenerator $batchNumbers,
        private readonly InventoryLedgerService $ledger,
        private readonly UnitConversionService $conversions,
        private readonly WarehouseResolver $warehouses,
    ) {}

    /**
     * @param  array{vendor_id?: int|null, warehouse_id: int, received_at: string, invoice_ref?: string|null, notes?: string|null}  $attributes
     * @param  list<array{item_id: int, quantity: string, uom_id: int, unit_price?: string|null, supplier_batch_ref?: string|null, manufactured_at?: string|null, expiry_at?: string|null, notes?: string|null}>  $lines
     */
    public function create(array $attributes, array $lines, ?int $userId = null): GoodsReceipt
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A goods receipt needs at least one line.');
        }

        return DB::transaction(function () use ($attributes, $lines, $userId): GoodsReceipt {
            $receivedAt = Carbon::parse($attributes['received_at']);

            $receipt = GoodsReceipt::create([
                'number' => $this->sequences->nextNumber('GRN', $receivedAt->format('ym')),
                'vendor_id' => $attributes['vendor_id'] ?? null,
                'warehouse_id' => $attributes['warehouse_id'],
                'received_at' => $receivedAt->toDateString(),
                'invoice_ref' => $attributes['invoice_ref'] ?? null,
                'notes' => $attributes['notes'] ?? null,
                'status' => GoodsReceiptStatus::Draft,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $item = Item::with('stockUom')->findOrFail($line['item_id']);
                $uom = Uom::findOrFail($line['uom_id']);

                $receipt->lines()->create([
                    'item_id' => $item->id,
                    'quantity' => $line['quantity'],
                    'uom_id' => $uom->id,
                    'stock_quantity' => (string) $this->conversions->toStockUom($item, $line['quantity'], $uom),
                    'unit_price' => $line['unit_price'] ?? null,
                    'supplier_batch_ref' => $line['supplier_batch_ref'] ?? null,
                    'manufactured_at' => $line['manufactured_at'] ?? null,
                    'expiry_at' => $line['expiry_at'] ?? null,
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            return $receipt;
        });
    }

    /**
     * Make the stock real.
     */
    public function post(GoodsReceipt $receipt, ?int $userId = null): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);

            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw new InvalidArgumentException("Receipt {$receipt->number} is {$receipt->status->value} and cannot be posted.");
            }

            $lines = $receipt->lines()->with('item.stockUom')->get();

            if ($lines->isEmpty()) {
                throw new InvalidArgumentException("Receipt {$receipt->number} has no lines.");
            }

            $quarantine = null;

            foreach ($lines as $line) {
                $item = $line->item;
                $needsQc = $item->requires_qc;

                $lot = InventoryLot::create([
                    'item_id' => $item->id,
                    'batch_number' => $this->batchNumbers->generate($item, $receipt->received_at),
                    'supplier_batch_ref' => $line->supplier_batch_ref,
                    'vendor_id' => $receipt->vendor_id,
                    'manufactured_at' => $line->manufactured_at,
                    'received_at' => $receipt->received_at,
                    'expiry_at' => $line->expiry_at,
                    'qc_status' => $needsQc ? LotQcStatus::Pending : LotQcStatus::NotRequired,
                    'initial_quantity' => $line->stock_quantity,
                    'unit_cost' => $line->unitCostPerStockUnit()?->__toString(),
                    'source_type' => $line->getMorphClass(),
                    'source_id' => $line->id,
                    'created_by' => $userId,
                ]);

                $target = $receipt->warehouse;

                if ($needsQc) {
                    $quarantine ??= $this->warehouses->quarantine();
                    $target = $quarantine;
                }

                $this->ledger->receive(
                    item: $item,
                    warehouse: $target,
                    quantity: $line->stock_quantity,
                    lot: $lot,
                    type: InventoryTransactionType::GrnReceipt,
                    reference: $receipt,
                    reason: "Goods receipt {$receipt->number}",
                    unitCost: $lot->unit_cost,
                    userId: $userId,
                    at: $receipt->received_at->copy()->setTimeFrom(now()),
                );

                $inspectionId = null;

                if ($needsQc) {
                    $inspection = QcInspection::create([
                        'number' => $this->sequences->nextNumber('QC', $receipt->received_at->format('ym')),
                        'lot_id' => $lot->id,
                        'item_id' => $item->id,
                        'goods_receipt_line_id' => $line->id,
                        'quantity' => $line->stock_quantity,
                        'status' => LotQcStatus::Pending,
                        'destination_warehouse_id' => $receipt->warehouse_id,
                        'created_by' => $userId,
                    ]);

                    $inspectionId = $inspection->id;
                }

                $line->update([
                    'batch_number' => $lot->batch_number,
                    'lot_id' => $lot->id,
                    'qc_inspection_id' => $inspectionId,
                ]);
            }

            $receipt->update([
                'status' => GoodsReceiptStatus::Received,
                'received_by' => $userId,
                'posted_at' => now(),
            ]);

            return $receipt;
        });
    }

    /**
     * A draft can be cancelled. A posted receipt has created stock, and that
     * stock is corrected through the ledger, not by unposting.
     */
    public function cancel(GoodsReceipt $receipt): GoodsReceipt
    {
        if ($receipt->status !== GoodsReceiptStatus::Draft) {
            throw new InvalidArgumentException("Receipt {$receipt->number} has been posted and cannot be cancelled; post a correcting transaction instead.");
        }

        $receipt->update(['status' => GoodsReceiptStatus::Cancelled]);

        return $receipt;
    }
}
