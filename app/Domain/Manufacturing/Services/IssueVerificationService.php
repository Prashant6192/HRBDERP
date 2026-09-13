<?php

declare(strict_types=1);

namespace App\Domain\Manufacturing\Services;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Manufacturing\Models\ManufacturingOrderLine;
use App\Domain\Manufacturing\Models\ManufacturingOrderScan;
use App\Domain\Planning\Enums\StoreKind;
use App\Support\Scanning\ScanCode;
use Carbon\CarbonImmutable;

/**
 * Scan before issue.
 *
 * At the kettle, every drum is scanned against the batch before it goes
 * in. The scan is allowed only when the material is on the recipe, the
 * batch has passed QC, it has not expired, it belongs to whoever this
 * batch is for, and it is the batch the store reserved for this order.
 * Anything else is blocked, and the block is recorded with its reason so
 * the wrong ingredient, the wrong batch, expired stock or the wrong
 * packaging never goes in unnoticed.
 */
class IssueVerificationService
{
    /**
     * @return array{verdict: string, reasons: list<string>, item: array<string, mixed>|null, lot: array<string, mixed>|null, line: array<string, mixed>|null}
     */
    public function check(ManufacturingOrder $order, string $code, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now();
        $order->loadMissing(['lines.item:id,code,name,type', 'lines.uom:id,code']);
        $parsed = ScanCode::parse($code);
        $reasons = [];

        $lot = match ($parsed['type']) {
            ScanCode::LOT, null => InventoryLot::query()->with(['item:id,code,name,type', 'ownerClient:id,name'])->where('batch_number', $parsed['value'])->first(),
            default => null,
        };

        if ($lot === null && $parsed['type'] === ScanCode::ITEM) {
            $reasons[] = 'Scan the batch sticker, not the material code: the batch is what is checked.';
        }

        if ($lot === null) {
            $reasons[] = $reasons === [] ? "No batch matches \"{$parsed['value']}\"." : $reasons[0];

            return ['verdict' => 'blocked', 'reasons' => array_values(array_unique($reasons)), 'item' => null, 'lot' => null, 'line' => null];
        }

        /** @var ManufacturingOrderLine|null $line */
        $line = $order->lines->firstWhere('item_id', $lot->item_id);

        if ($line === null) {
            $reasons[] = "{$lot->item?->name} is not on {$order->number}: wrong ingredient or packaging for this batch.";
        }

        if (! $lot->qc_status->isReleasable()) {
            $reasons[] = "Batch {$lot->batch_number} has not been released by QC ({$lot->qc_status->label()}).";
        }

        if ($lot->isExpired($asOf)) {
            $reasons[] = "Batch {$lot->batch_number} expired on ".$lot->expiry_at?->format('j M Y').'.';
        }

        $owner = $order->ownerForItem($lot->item_id);

        if ($lot->owner_client_id !== $owner) {
            $reasons[] = $lot->owner_client_id === null
                ? 'This is company stock; this line must come from the client\'s own material.'
                : "Batch {$lot->batch_number} belongs to ".($lot->ownerClient?->name ?? 'another client').', not to this batch\'s owner.';
        }

        if ($line !== null && in_array($order->status, [ManufacturingOrderStatus::Approved, ManufacturingOrderStatus::InProgress], true)) {
            $reservedLots = $order->reservations()->whereIn('status', ['active', 'consumed'])->where('item_id', $lot->item_id)->pluck('lot_id')->filter()->all();

            if ($reservedLots !== [] && ! in_array($lot->id, $reservedLots, true)) {
                $reasons[] = "Batch {$lot->batch_number} is not the one held for this order; the store reserved ".InventoryLot::query()->whereIn('id', $reservedLots)->pluck('batch_number')->implode(', ').'.';
            }
        }

        if ($line !== null && $order->status === ManufacturingOrderStatus::Draft) {
            $reasons[] = "{$order->number} is not approved yet; nothing is issued to it.";
        }

        if ($line !== null && $order->status === ManufacturingOrderStatus::Completed) {
            $reasons[] = "{$order->number} is already complete.";
        }

        $onHand = StockBalance::query()->where('lot_id', $lot->id)->sum('on_hand');

        if ((float) $onHand <= 0) {
            $reasons[] = "Batch {$lot->batch_number} has no stock left.";
        }

        return [
            'verdict' => $reasons === [] ? 'ok' : 'blocked',
            'reasons' => array_values(array_unique($reasons)),
            'item' => $lot->item === null ? null : ['id' => $lot->item->id, 'code' => $lot->item->code, 'name' => $lot->item->name],
            'lot' => ['id' => $lot->id, 'batch_number' => $lot->batch_number, 'expiry_at' => $lot->expiry_at?->toDateString(), 'qc_status' => $lot->qc_status->value, 'owner' => $lot->ownerClient?->name],
            'line' => $line === null ? null : ['id' => $line->id, 'store_kind' => $line->store_kind->value, 'planned' => $line->planned_quantity, 'unit' => $line->uom?->code],
        ];
    }

    /**
     * Check and record.
     *
     * @return array{verdict: string, reasons: list<string>, item: array<string, mixed>|null, lot: array<string, mixed>|null, line: array<string, mixed>|null, scan_id: int}
     */
    public function scan(ManufacturingOrder $order, string $code, ?int $userId): array
    {
        $result = $this->check($order, $code);

        $scan = $order->scans()->create([
            'code' => mb_substr(trim($code), 0, 191),
            'item_id' => $result['item']['id'] ?? null,
            'lot_id' => $result['lot']['id'] ?? null,
            'verdict' => $result['verdict'],
            'reasons' => $result['reasons'],
            'scanned_by' => $userId,
            'scanned_at' => now(),
        ]);

        return [...$result, 'scan_id' => $scan->id];
    }

    /**
     * Per line, whether a passing scan has been recorded for it.
     *
     * @return array{lines: list<array<string, mixed>>, verified: int, required: int, complete: bool, recent: list<array<string, mixed>>}
     */
    public function status(ManufacturingOrder $order, StoreKind $kind = StoreKind::RawMaterial): array
    {
        $order->loadMissing(['lines.item:id,code,name', 'lines.uom:id,code']);
        $ok = $order->scans()->where('verdict', 'ok')->get()->groupBy('item_id');

        $lines = $order->lines
            ->where('store_kind', $kind)
            ->map(fn (ManufacturingOrderLine $l) => [
                'item_id' => $l->item_id,
                'code' => $l->item?->code,
                'name' => $l->item?->name,
                'planned' => $l->planned_quantity,
                'unit' => $l->uom?->code,
                'verified' => $ok->has($l->item_id),
                'verified_at' => $ok->get($l->item_id)?->max('scanned_at')?->toIso8601String(),
            ])
            ->values();

        $recent = $order->scans()->with(['scannedBy:id,name', 'lot:id,batch_number', 'item:id,name'])->orderByDesc('id')->limit(12)->get()
            ->map(fn (ManufacturingOrderScan $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'verdict' => $s->verdict,
                'reasons' => $s->reasons,
                'item' => $s->item?->name,
                'batch' => $s->lot?->batch_number,
                'by' => $s->scannedBy?->name,
                'at' => $s->scanned_at->toIso8601String(),
            ])->all();

        $verified = $lines->where('verified', true)->count();

        return [
            'lines' => $lines->all(),
            'verified' => $verified,
            'required' => $lines->count(),
            'complete' => $lines->count() === 0 || $verified === $lines->count(),
            'recent' => $recent,
        ];
    }
}
