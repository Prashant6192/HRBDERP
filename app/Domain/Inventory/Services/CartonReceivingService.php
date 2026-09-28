<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Exceptions\StockTransferException;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferLine;
use App\Domain\Inventory\Models\TransferCartonScan;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use App\Support\Scanning\ScanCode;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receiving a lorry by scanning its cartons.
 *
 * Each carton sticker carries its batch, box number and units. Scanning
 * one at the destination finds the transfer on the road that carries that
 * batch, and the first carton scanned verifies the consignment the way the
 * challan QR does. A carton counts once. When the last carton is scanned
 * the transfer books itself into the store, through the same receipt as
 * receiving by hand, so the ledger, inspection and discrepancy rules are
 * the ones already in force. A short lorry can be booked in with what was
 * scanned; the rest stays in transit until found or written off.
 */
class CartonReceivingService
{
    public function __construct(private readonly StockTransferService $transfers) {}

    /**
     * @return array{scan: TransferCartonScan, transfer: StockTransfer, booked: bool, verified_now: bool}
     */
    public function scan(User $user, Facility $facility, string $raw, ?string $units = null, ?string $device = null): array
    {
        $parsed = ScanCode::parse($raw);
        $carton = $parsed['type'] === ScanCode::CARTON || $parsed['type'] === null ? ScanCode::parseCarton($parsed['value']) : null;

        if ($parsed['type'] !== null && ! in_array($parsed['type'], [ScanCode::CARTON, ScanCode::LOT], true)) {
            throw new StockTransferException('That is not a carton or batch sticker. Scan the QR on the carton.');
        }

        $batch = $carton['batch'] ?? trim($parsed['value']);
        $lot = InventoryLot::query()->where('batch_number', $batch)->first()
            ?? InventoryLot::query()->whereRaw('upper(batch_number) = ?', [strtoupper($batch)])->first();

        if ($lot === null) {
            throw new StockTransferException("No batch {$batch} in the ERP. Check the sticker, or scan the challan on the transfer page.");
        }

        $transfer = $this->transferFor($facility, $lot);

        return DB::transaction(function () use ($user, $raw, $units, $device, $carton, $lot, $transfer): array {
            $transfer = StockTransfer::query()->lockForUpdate()->with('lines')->findOrFail($transfer->id);
            $line = $this->lineFor($transfer, $lot);

            // A batch sticker has no box number or count: the box is the next
            // one, and the units are what the carton plan or the person says.
            $box = $carton['box'] ?? ((int) TransferCartonScan::query()->where('stock_transfer_id', $transfer->id)->where('lot_id', $lot->id)->max('box_no') + 1);
            $count = $carton['units'] ?? ($units !== null && trim($units) !== '' ? trim($units) : ($lot->carton_plan['units_per_box'] ?? null));

            if ($count === null || ! is_numeric((string) $count) || BigDecimal::of((string) $count)->isLessThanOrEqualTo(0)) {
                throw new StockTransferException("How many units are in this carton of {$lot->batch_number}? This sticker does not say; enter the count and scan again.");
            }

            $already = TransferCartonScan::query()->with('scanner:id,name')
                ->where('stock_transfer_id', $transfer->id)->where('lot_id', $lot->id)->where('box_no', $box)->first();

            if ($already !== null) {
                throw new StockTransferException(sprintf(
                    'Carton %d of %s was already scanned at %s by %s. It is not counted twice.',
                    $box, $lot->batch_number, $already->scanned_at->format('H:i'), $already->scanner?->name ?? 'someone',
                ));
            }

            $quantity = BigDecimal::of((string) $count);
            $room = $line->outstanding()->minus($this->unbooked($transfer, $line->id));

            if ($quantity->isGreaterThan($room)) {
                throw new StockTransferException(sprintf(
                    'Every unit of %s on %s is already counted (%s left to scan, this carton has %s). If it really is extra, book in and report it on the transfer.',
                    $lot->batch_number, $transfer->number, $room->strippedOfTrailingZeros(), $quantity->strippedOfTrailingZeros(),
                ));
            }

            $verifiedNow = false;

            if (! $transfer->isVerified()) {
                $transfer->forceFill(['scanned_at' => now(), 'scanned_by' => $user->id])->save();
                $verifiedNow = true;
            }

            $scan = TransferCartonScan::create([
                'stock_transfer_id' => $transfer->id,
                'stock_transfer_line_id' => $line->id,
                'lot_id' => $lot->id,
                'box_no' => $box,
                'units' => $quantity->__toString(),
                'code' => mb_substr(trim($raw), 0, 128),
                'device' => $device !== null ? mb_substr($device, 0, 160) : null,
                'scanned_by' => $user->id,
                'scanned_at' => now(),
            ]);

            $booked = false;

            if ($this->complete($transfer)) {
                $this->book($transfer, $user);
                $booked = true;
            }

            return ['scan' => $scan, 'transfer' => $transfer->refresh(), 'booked' => $booked, 'verified_now' => $verifiedNow];
        });
    }

    /**
     * Book into the store every carton scanned but not yet booked.
     */
    public function book(StockTransfer $transfer, User $user): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $user): StockTransfer {
            $scans = TransferCartonScan::query()->where('stock_transfer_id', $transfer->id)->whereNull('booked_at')->lockForUpdate()->get();

            if ($scans->isEmpty()) {
                throw new StockTransferException("No carton of {$transfer->number} is waiting to be booked in. Scan the cartons first.");
            }

            $received = $scans->groupBy('stock_transfer_line_id')
                ->map(fn (Collection $group) => ['quantity' => $group->reduce(fn (BigDecimal $c, TransferCartonScan $s) => $c->plus($s->units()), BigDecimal::zero())->__toString()])
                ->all();

            $transfer = $this->transfers->receive($transfer, $user->id, $received);

            TransferCartonScan::query()->whereIn('id', $scans->pluck('id'))->update(['booked_at' => now()]);

            return $transfer;
        });
    }

    /**
     * Lorries on the road to this facility, oldest first.
     *
     * @return Builder<StockTransfer>
     */
    public function inbound(Facility $facility): Builder
    {
        return StockTransfer::query()->where('destination_facility_id', $facility->id)
            ->whereIn('status', [StockTransferStatus::Dispatched->value, StockTransferStatus::InTransit->value, StockTransferStatus::PartiallyReceived->value])
            ->orderBy('dispatched_at')->orderBy('id');
    }

    /**
     * Units scanned on a line and not yet booked in.
     */
    public function unbooked(StockTransfer $transfer, int $lineId): BigDecimal
    {
        return TransferCartonScan::query()->where('stock_transfer_id', $transfer->id)->where('stock_transfer_line_id', $lineId)->whereNull('booked_at')
            ->pluck('units')->reduce(fn (BigDecimal $c, $u) => $c->plus(BigDecimal::of((string) $u)), BigDecimal::zero());
    }

    /**
     * The lorry that carries this batch here and still has room for it.
     */
    private function transferFor(Facility $facility, InventoryLot $lot): StockTransfer
    {
        $candidates = $this->inbound($facility)
            ->whereHas('lines', fn ($q) => $q->where(fn ($l) => $l->where('lot_id', $lot->id)
                ->orWhere(fn ($n) => $n->whereNull('lot_id')->where('item_id', $lot->item_id))))
            ->with('lines')->get();

        if ($candidates->isEmpty()) {
            throw new StockTransferException("Batch {$lot->batch_number} is not on any lorry coming to {$facility->name}. Check the carton, or open the transfer it belongs to.");
        }

        foreach ($candidates as $transfer) {
            $line = $this->lineFor($transfer, $lot);

            if ($line->outstanding()->minus($this->unbooked($transfer, $line->id))->isPositive()) {
                return $transfer;
            }
        }

        return $candidates->first();
    }

    private function lineFor(StockTransfer $transfer, InventoryLot $lot): StockTransferLine
    {
        /** @var StockTransferLine|null $line */
        $line = $transfer->lines->first(fn (StockTransferLine $l) => $l->lot_id === $lot->id)
            ?? $transfer->lines->first(fn (StockTransferLine $l) => $l->lot_id === null && $l->item_id === $lot->item_id);

        if ($line === null) {
            throw new StockTransferException("Batch {$lot->batch_number} is not on {$transfer->number}.");
        }

        return $line;
    }

    /**
     * Whether every unit still on the road has now been scanned.
     */
    private function complete(StockTransfer $transfer): bool
    {
        $any = false;

        foreach ($transfer->lines as $line) {
            $outstanding = $line->outstanding();

            if (! $outstanding->isPositive()) {
                continue;
            }

            $any = true;

            if ($this->unbooked($transfer, $line->id)->isLessThan($outstanding)) {
                return false;
            }
        }

        return $any;
    }
}
