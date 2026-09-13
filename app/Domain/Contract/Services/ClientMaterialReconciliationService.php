<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Domain\Contract\Models\Client;
use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransactionLine;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Enums\ItemType;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * The statement a client is given about their own material: what they
 * sent, what production took, what was lost, and what is still with us.
 * Everything comes off the ledger, batch by batch, so it agrees with the
 * stores to the gram.
 */
class ClientMaterialReconciliationService
{
    private const array WASTAGE_TYPES = [
        InventoryTransactionType::Damage,
        InventoryTransactionType::Expiry,
        InventoryTransactionType::Sample,
        InventoryTransactionType::StockAdjustmentOut,
        InventoryTransactionType::QcRejection,
    ];

    /**
     * One row per material the client owns (finished goods excluded), or,
     * for an order, per material the client supplies to that job.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Client $client, ?ManufacturingOrder $order = null): array
    {
        $lots = InventoryLot::query()
            ->where('owner_client_id', $client->id)
            ->whereHas('item', fn ($q) => $q->where('type', '!=', ItemType::FinishedGood->value))
            ->when($order !== null, fn ($q) => $q->whereIn('item_id', $order->clientSuppliedItemIds()))
            ->with('item.stockUom:id,code,display_scale')
            ->get();

        if ($lots->isEmpty()) {
            return [];
        }

        $lotIds = $lots->pluck('id')->all();

        $onHand = StockBalance::query()
            ->whereIn('lot_id', $lotIds)
            ->selectRaw('lot_id, SUM(on_hand) AS on_hand')
            ->groupBy('lot_id')
            ->pluck('on_hand', 'lot_id');

        $movements = InventoryTransactionLine::query()
            ->whereIn('lot_id', $lotIds)
            ->where('quantity', '<', 0)
            ->with('transaction:id,type,reference_type,reference_id')
            ->get();

        $byItem = [];

        foreach ($lots as $lot) {
            $row = &$byItem[$lot->item_id];
            $row ??= [
                'item_id' => $lot->item_id,
                'item_code' => $lot->item->code,
                'item_name' => $lot->item->name,
                'item_type' => $lot->item->type->value,
                'uom' => $lot->item->stockUom?->code,
                'lots' => 0,
                'supplied' => BigDecimal::zero(),
                'consumed' => BigDecimal::zero(),
                'consumed_on_job' => BigDecimal::zero(),
                'wastage' => BigDecimal::zero(),
                'balance' => BigDecimal::zero(),
            ];

            $row['lots']++;
            $row['supplied'] = $row['supplied']->plus(BigDecimal::of($lot->initial_quantity));
            $row['balance'] = $row['balance']->plus(BigDecimal::of($onHand->get($lot->id, '0')));

            foreach ($movements->where('lot_id', $lot->id) as $line) {
                $taken = BigDecimal::of($line->quantity)->abs();
                $type = $line->transaction?->type;

                if ($type === InventoryTransactionType::ProductionConsumption) {
                    $row['consumed'] = $row['consumed']->plus($taken);

                    if ($order !== null && $line->transaction?->reference_type === $order->getMorphClass() && (int) $line->transaction?->reference_id === $order->id) {
                        $row['consumed_on_job'] = $row['consumed_on_job']->plus($taken);
                    }
                } elseif (in_array($type, self::WASTAGE_TYPES, strict: true)) {
                    $row['wastage'] = $row['wastage']->plus($taken);
                }
            }

            unset($row);
        }

        return (new Collection($byItem))
            ->sortBy('item_name')
            ->map(fn (array $row): array => [
                ...$row,
                'supplied' => $row['supplied']->__toString(),
                'consumed' => $row['consumed']->__toString(),
                'consumed_on_job' => $row['consumed_on_job']->__toString(),
                'wastage' => $row['wastage']->__toString(),
                'balance' => $row['balance']->__toString(),
            ])
            ->values()
            ->all();
    }
}
