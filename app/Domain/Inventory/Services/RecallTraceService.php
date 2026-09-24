<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\InventoryTransactionType;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recall management.
 *
 * If a lot is later found defective, everything it touched is found from
 * the ledger: the batches it was consumed into, the finished goods those
 * batches produced (and, through them, anything made from those), where
 * every affected lot sits now, where it was transferred, and which client
 * owns it. Backwards, the same ledger says which lots a finished batch was
 * made from and who supplied them.
 */
class RecallTraceService
{
    /**
     * @return array<string, mixed>
     */
    public function trace(InventoryLot $lot): array
    {
        $lot->loadMissing(['item:id,code,name,type,stock_uom_id', 'item.stockUom:id,code', 'vendor:id,name', 'ownerClient:id,name']);

        $visited = [];
        $affected = collect();
        $orders = collect();
        $this->forward($lot, $visited, $affected, $orders, 0);

        $lots = $affected->values();
        $lotIds = $lots->pluck('lot')->pluck('id')->push($lot->id)->unique()->all();

        $positions = DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('facilities as f', 'f.id', '=', 'w.facility_id')
            ->whereIn('b.lot_id', $lotIds)
            ->where('b.on_hand', '>', 0)
            ->selectRaw('b.lot_id, w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name, f.id AS facility_id, f.name AS facility_name, SUM(b.on_hand) AS on_hand')
            ->groupBy('b.lot_id', 'w.id', 'w.code', 'w.name', 'f.id', 'f.name')
            ->get()
            ->groupBy('lot_id');

        $transfers = DB::table('stock_transfer_lines as l')
            ->join('stock_transfers as t', 't.id', '=', 'l.stock_transfer_id')
            ->join('facilities as f', 'f.id', '=', 't.destination_facility_id')
            ->whereIn('l.lot_id', $lotIds)
            ->whereIn('t.status', ['dispatched', 'in_transit', 'partially_received', 'received', 'discrepancy'])
            ->selectRaw('l.lot_id, t.id, t.number, t.status, f.name AS destination, t.dispatched_at, l.quantity_dispatched')
            ->get()
            ->groupBy('lot_id');

        $dispatched = DB::table('inventory_transaction_lines as l')
            ->join('inventory_transactions as t', 't.id', '=', 'l.inventory_transaction_id')
            ->whereIn('l.lot_id', $lotIds)
            ->whereIn('t.type', [InventoryTransactionType::SalesDispatch->value, InventoryTransactionType::MarketplaceSale->value, InventoryTransactionType::MarketplaceTransfer->value])
            ->where('l.quantity', '<', 0)
            ->selectRaw('l.lot_id, SUM(ABS(l.quantity)) AS quantity, MAX(t.transacted_at) AS last_at')
            ->groupBy('l.lot_id')
            ->get()
            ->keyBy('lot_id');

        $describe = function (InventoryLot $l, int $depth, ?string $via) use ($positions, $transfers, $dispatched): array {
            $where = ($positions->get($l->id) ?? collect())->map(fn ($p) => [
                'facility' => $p->facility_name,
                'warehouse' => $p->warehouse_code.' — '.$p->warehouse_name,
                'on_hand' => Decimal::strip((string) $p->on_hand),
            ])->values()->all();
            $onHand = array_reduce($where, fn (BigDecimal $c, array $w) => $c->plus(BigDecimal::of($w['on_hand'])), BigDecimal::zero());
            $out = $dispatched->get($l->id);

            return [
                'lot' => ['id' => $l->id, 'batch_number' => $l->batch_number],
                'item' => ['id' => $l->item_id, 'code' => $l->item?->code, 'name' => $l->item?->name, 'type' => $l->item?->type?->value, 'unit' => $l->item?->stockUom?->code],
                'depth' => $depth,
                'via' => $via,
                'qc_status' => $l->qc_status->value,
                'client' => $l->ownerClient?->name,
                'vendor' => $l->vendor?->name,
                'on_hand' => Decimal::strip($onHand),
                'where' => $where,
                'transfers' => ($transfers->get($l->id) ?? collect())->map(fn ($t) => [
                    'id' => $t->id, 'number' => $t->number, 'status' => $t->status, 'destination' => $t->destination, 'quantity' => Decimal::strip((string) $t->quantity_dispatched),
                ])->values()->all(),
                'dispatched' => $out === null ? null : ['quantity' => Decimal::strip((string) $out->quantity), 'last_at' => $out->last_at],
            ];
        };

        $rows = $lots->map(fn (array $a) => $describe($a['lot'], $a['depth'], $a['via']))->values()->all();
        $facilities = collect($rows)->flatMap(fn (array $r) => collect($r['where'])->pluck('facility'))->merge(collect($rows)->flatMap(fn (array $r) => collect($r['transfers'])->pluck('destination')))->filter()->unique()->values()->all();
        $clients = collect($rows)->pluck('client')->push($lot->ownerClient?->name)->filter()->unique()->values()->all();

        return [
            'origin' => $describe($lot, 0, null),
            'backward' => $this->backward($lot),
            'orders' => $orders->values()->all(),
            'affected' => $rows,
            'summary' => [
                'batches' => count($rows),
                'orders' => $orders->count(),
                'finished_goods' => collect($rows)->filter(fn (array $r) => $r['item']['type'] === 'finished_good')->count(),
                'facilities' => $facilities,
                'clients' => $clients,
                'dispatched' => collect($rows)->filter(fn (array $r) => $r['dispatched'] !== null)->count(),
                'in_stock' => collect($rows)->filter(fn (array $r) => BigDecimal::of($r['on_hand'])->isPositive())->count(),
            ],
        ];
    }

    /**
     * Forward: the batches this lot went into, and their outputs, and so on.
     *
     * @param  array<int, bool>  $visited
     * @param  Collection<int, array{lot: InventoryLot, depth: int, via: string|null}>  $affected
     * @param  Collection<int, array<string, mixed>>  $orders
     */
    private function forward(InventoryLot $lot, array &$visited, Collection $affected, Collection $orders, int $depth): void
    {
        if (isset($visited[$lot->id]) || $depth > 6) {
            return;
        }

        $visited[$lot->id] = true;

        $consumptions = DB::table('inventory_transaction_lines as l')
            ->join('inventory_transactions as t', 't.id', '=', 'l.inventory_transaction_id')
            ->where('l.lot_id', $lot->id)
            ->where('t.type', InventoryTransactionType::ProductionConsumption->value)
            ->where('t.reference_type', (new ManufacturingOrder)->getMorphClass())
            ->selectRaw('t.reference_id AS order_id, SUM(ABS(l.quantity)) AS quantity')
            ->groupBy('t.reference_id')
            ->get();

        foreach ($consumptions as $c) {
            $order = ManufacturingOrder::query()->with(['product:id,name', 'client:id,name', 'outputLot.item:id,code,name,type,stock_uom_id', 'outputLot.item.stockUom:id,code', 'outputLot.ownerClient:id,name'])->find($c->order_id);

            if ($order === null) {
                continue;
            }

            if (! $orders->has($order->id)) {
                $orders->put($order->id, [
                    'id' => $order->id,
                    'number' => $order->number,
                    'product' => $order->product?->name,
                    'client' => $order->client?->name,
                    'status' => $order->status->value,
                    'completed_at' => $order->completed_at?->toDateString(),
                    'consumed' => Decimal::strip((string) $c->quantity),
                    'from_batch' => $lot->batch_number,
                    'output_batch' => $order->outputLot?->batch_number,
                ]);
            }

            if ($order->outputLot !== null && ! $affected->has($order->outputLot->id)) {
                $affected->put($order->outputLot->id, ['lot' => $order->outputLot, 'depth' => $depth + 1, 'via' => $order->number]);
                $this->forward($order->outputLot, $visited, $affected, $orders, $depth + 1);
            }
        }
    }

    /**
     * Backward: for a batch made here, the lots it was made from and who
     * supplied them.
     *
     * @return list<array<string, mixed>>
     */
    private function backward(InventoryLot $lot): array
    {
        $order = ManufacturingOrder::query()->where('output_lot_id', $lot->id)->first();

        if ($order === null) {
            return [];
        }

        return DB::table('inventory_transaction_lines as l')
            ->join('inventory_transactions as t', 't.id', '=', 'l.inventory_transaction_id')
            ->join('inventory_lots as il', 'il.id', '=', 'l.lot_id')
            ->join('items as i', 'i.id', '=', 'il.item_id')
            ->leftJoin('vendors as v', 'v.id', '=', 'il.vendor_id')
            ->where('t.type', InventoryTransactionType::ProductionConsumption->value)
            ->where('t.reference_type', $order->getMorphClass())
            ->where('t.reference_id', $order->id)
            ->selectRaw('il.id AS lot_id, il.batch_number, il.supplier_batch_ref, i.code, i.name, v.name AS vendor, SUM(ABS(l.quantity)) AS quantity')
            ->groupBy('il.id', 'il.batch_number', 'il.supplier_batch_ref', 'i.code', 'i.name', 'v.name')
            ->get()
            ->map(fn ($r) => [
                'lot_id' => (int) $r->lot_id,
                'batch_number' => $r->batch_number,
                'supplier_batch_ref' => $r->supplier_batch_ref,
                'code' => $r->code,
                'name' => $r->name,
                'vendor' => $r->vendor,
                'quantity' => Decimal::strip((string) $r->quantity),
                'order' => $order->number,
            ])
            ->values()
            ->all();
    }
}
