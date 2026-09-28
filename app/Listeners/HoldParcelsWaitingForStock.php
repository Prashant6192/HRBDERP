<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Inventory\Events\StockArrived;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\OnlineOrderService;
use Throwable;

/**
 * Parcels marked short are held the moment their goods land in the store,
 * oldest first, without anyone pressing "Check stock again".
 */
class HoldParcelsWaitingForStock
{
    public function __construct(private readonly OnlineOrderService $orders) {}

    public function handle(StockArrived $event): void
    {
        foreach ($event->items as $warehouseId => $itemIds) {
            try {
                Shipment::query()
                    ->where('warehouse_id', $warehouseId)
                    ->whereIn('status', ShipmentStatus::awaitingPacking())
                    ->where('stock_state', StockState::Short->value)
                    ->whereHas('picks', fn ($q) => $q->whereIn('item_id', $itemIds))
                    ->orderBy('id')
                    ->get()
                    ->each(fn (Shipment $s) => $this->orders->hold($s));
            } catch (Throwable $e) {
                // The stock is in; a parcel left short is tried again at
                // print or packing. Never undo a receipt over it.
                report($e);
            }
        }
    }
}
