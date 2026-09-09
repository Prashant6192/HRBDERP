<?php

declare(strict_types=1);

namespace App\Domain\Planning\Services;

use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Procurement\Models\GoodsReceipt;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a material request's lines in step with what has been received
 * against it.
 */
class MaterialRequestService
{
    /**
     * A delivery booked in against a request covers its lines, item by item.
     * Called by GoodsReceiptService when such a receipt is posted.
     */
    public function recordReceipt(GoodsReceipt $receipt): void
    {
        if ($receipt->material_request_id === null) {
            return;
        }

        DB::transaction(function () use ($receipt): void {
            $request = MaterialRequest::query()->lockForUpdate()->with('lines')->findOrFail($receipt->material_request_id);

            if (! $request->isOpen()) {
                return;
            }

            foreach ($receipt->lines as $receiptLine) {
                $line = $request->lines->firstWhere('item_id', $receiptLine->item_id);

                if ($line === null) {
                    continue;
                }

                $line->received_quantity = BigDecimal::of($line->received_quantity)
                    ->plus($receiptLine->stock_quantity)
                    ->__toString();
                $line->save();
            }

            $request->load('lines');

            $covered = $request->lines->every(fn ($line) => $line->isCovered());
            $anyReceived = $request->lines->contains(fn ($line) => BigDecimal::of($line->received_quantity)->isPositive());

            $request->fill([
                'status' => $covered ? MaterialRequestStatus::Fulfilled : ($anyReceived ? MaterialRequestStatus::PartiallyReceived : MaterialRequestStatus::Open),
                'fulfilled_at' => $covered ? now() : null,
            ])->save();
        });
    }

    public function cancel(MaterialRequest $request): MaterialRequest
    {
        if (! $request->isOpen()) {
            throw new PlanningException("{$request->number} is {$request->status->label()} and cannot be cancelled.");
        }

        $request->fill(['status' => MaterialRequestStatus::Cancelled, 'cancelled_at' => now()])->save();

        return $request;
    }
}
