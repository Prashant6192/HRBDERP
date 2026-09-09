<?php

declare(strict_types=1);

namespace App\Domain\Quality\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Services\InventoryLedgerService;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Warehousing\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Quality's three verbs.
 *
 * Approve releases the lot: its status changes and every unit of it moves
 * from quarantine to the destination store in one ledger posting. Reject
 * marks the lot; the stock stays where it is, on the books and unissuable,
 * until procurement returns it. Hold pauses the decision.
 */
class QcInspectionService
{
    public function __construct(
        private readonly InventoryLedgerService $ledger,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>|null  $parameters
     */
    public function approve(QcInspection $inspection, int $userId, ?string $remarks = null, ?array $parameters = null, ?Warehouse $destination = null): QcInspection
    {
        return DB::transaction(function () use ($inspection, $userId, $remarks, $parameters, $destination): QcInspection {
            $inspection = $this->lockOpen($inspection);
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($inspection->lot_id);
            $destination ??= $inspection->destinationWarehouse;

            if ($destination->is_quarantine || ! $destination->is_active) {
                throw new InvalidArgumentException("{$destination->code} is not a store that released stock can go to.");
            }

            // The lot's stock is wherever the receipt put it — normally one
            // quarantine store. Move all of it.
            $held = StockBalance::query()
                ->where('lot_id', $lot->id)
                ->where('on_hand', '>', 0)
                ->lockForUpdate()
                ->get();

            foreach ($held as $balance) {
                if ($balance->warehouse_id === $destination->id) {
                    continue;
                }

                $this->ledger->transfer(
                    item: $lot->item,
                    lot: $lot,
                    from: $balance->warehouse,
                    to: $destination,
                    quantity: $balance->on_hand,
                    type: InventoryTransactionType::QcRelease,
                    reference: $inspection,
                    reason: "QC approved — {$inspection->number}",
                    userId: $userId,
                );
            }

            $lot->update([
                'qc_status' => LotQcStatus::Approved,
                'qc_decided_at' => now(),
                'qc_decided_by' => $userId,
            ]);

            $inspection->update([
                'status' => LotQcStatus::Approved,
                'destination_warehouse_id' => $destination->id,
                'decided_by' => $userId,
                'decided_at' => now(),
                'remarks' => $remarks,
                'parameters' => $parameters,
            ]);

            $this->audit->log(
                AuditAction::Approved,
                $inspection,
                description: "QC approved batch {$lot->batch_number}; released to {$destination->code}.",
                context: ['batch_number' => $lot->batch_number, 'warehouse' => $destination->code],
            );

            return $inspection;
        });
    }

    public function reject(QcInspection $inspection, int $userId, string $remarks, ?array $parameters = null): QcInspection
    {
        return DB::transaction(function () use ($inspection, $userId, $remarks, $parameters): QcInspection {
            $inspection = $this->lockOpen($inspection);
            $lot = InventoryLot::query()->lockForUpdate()->findOrFail($inspection->lot_id);

            $lot->update([
                'qc_status' => LotQcStatus::Rejected,
                'qc_decided_at' => now(),
                'qc_decided_by' => $userId,
            ]);

            $inspection->update([
                'status' => LotQcStatus::Rejected,
                'decided_by' => $userId,
                'decided_at' => now(),
                'remarks' => $remarks,
                'parameters' => $parameters,
            ]);

            $this->audit->log(
                AuditAction::Rejected,
                $inspection,
                description: "QC rejected batch {$lot->batch_number}. Stock remains in quarantine pending return.",
                context: ['batch_number' => $lot->batch_number, 'remarks' => $remarks],
            );

            return $inspection;
        });
    }

    public function hold(QcInspection $inspection, int $userId, ?string $remarks = null): QcInspection
    {
        return DB::transaction(function () use ($inspection, $remarks): QcInspection {
            $inspection = $this->lockOpen($inspection);

            InventoryLot::whereKey($inspection->lot_id)->update(['qc_status' => LotQcStatus::OnHold->value]);

            $inspection->update([
                'status' => LotQcStatus::OnHold,
                'remarks' => $remarks,
            ]);

            return $inspection;
        });
    }

    private function lockOpen(QcInspection $inspection): QcInspection
    {
        $inspection = QcInspection::query()->lockForUpdate()->findOrFail($inspection->id);

        if (! $inspection->isOpen()) {
            throw new InvalidArgumentException("Inspection {$inspection->number} has already been decided ({$inspection->status->value}).");
        }

        return $inspection;
    }
}
