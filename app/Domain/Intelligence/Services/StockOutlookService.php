<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Enums\MaterialRequestStatus;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Procurement\Enums\GoodsReceiptStatus;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Predictive reordering.
 *
 * Rather than waiting for a material to fall Low or Critical, this looks at
 * what is usable now, what running and planned production will take, what
 * purchase has already asked for, how fast the material is used, and how
 * long the supplier takes — and says when to order, how much, and from
 * whom. Every number comes from the ledger and the planning documents;
 * nothing is estimated that can be read.
 */
class StockOutlookService
{
    public function __construct(private readonly ConsumptionRateService $rates) {}

    /**
     * The outlook for one material.
     */
    public function forItem(Item $item, ?Facility $facility = null, ?CarbonImmutable $asOf = null): ItemOutlook
    {
        return $this->forItems(new EloquentCollection([$item]), $facility, $asOf)->first();
    }

    /**
     * Every material that needs attention: an order due, or stock that will
     * not last the lead time. Most urgent first.
     *
     * @param  list<ItemType>|null  $types
     * @return Collection<int, ItemOutlook>
     */
    public function reorderAdvice(?Facility $facility = null, ?array $types = null, bool $onlyActionable = true, ?CarbonImmutable $asOf = null): Collection
    {
        $types ??= [ItemType::RawMaterial, ItemType::PackagingMaterial];

        $items = Item::query()
            ->with('stockUom:id,code,display_scale')
            ->whereIn('type', array_map(fn (ItemType $t) => $t->value, $types))
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $outlooks = $this->forItems($items, $facility, $asOf);

        if ($onlyActionable) {
            $outlooks = $outlooks->filter(fn (ItemOutlook $o) => $o->status !== ItemOutlook::OK);
        }

        $rank = [ItemOutlook::ORDER_TODAY => 0, ItemOutlook::ORDER_SOON => 1, ItemOutlook::WATCH => 2, ItemOutlook::OK => 3];

        return $outlooks
            ->sortBy([
                fn (ItemOutlook $a, ItemOutlook $b) => $rank[$a->status] <=> $rank[$b->status],
                fn (ItemOutlook $a, ItemOutlook $b) => ($a->orderBy?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->orderBy?->getTimestamp() ?? PHP_INT_MAX),
                fn (ItemOutlook $a, ItemOutlook $b) => strcmp($a->name, $b->name),
            ])
            ->values();
    }

    /**
     * Outlooks for many items with a fixed number of queries, whatever the
     * count. Items must have their stock unit loaded or loadable.
     *
     * @param  Collection<int, Item>  $items
     * @return Collection<int, ItemOutlook>
     */
    public function forItems(Collection $items, ?Facility $facility = null, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $today = $asOf->startOfDay();
        $ids = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($ids === []) {
            return collect();
        }

        $storeIds = $facility?->stores()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($items instanceof EloquentCollection) {
            $items->loadMissing('stockUom:id,code,display_scale');
        }

        $balances = $this->balances($ids, $storeIds, $today);
        $rates = $this->rates->ratesFor($ids, $storeIds, $asOf);
        $orders = $this->orderDemand($ids, $facility);
        $plans = $this->planDemand($ids, $facility);
        $onOrder = $this->onOrder($ids, $storeIds);
        $vendors = $this->vendorAdvice($ids, $asOf);

        $horizon = (int) config('erp.intelligence.planning_horizon_days', 30);
        $defaultLead = (int) config('erp.intelligence.default_lead_time_days', 7);
        $soonDays = (int) config('erp.intelligence.order_soon_days', 7);

        return $items->map(function (Item $item) use ($balances, $rates, $orders, $plans, $onOrder, $vendors, $horizon, $defaultLead, $soonDays, $today, $asOf): ItemOutlook {
            $b = $balances->get($item->id, ['on_hand' => BigDecimal::zero(), 'reserved' => BigDecimal::zero(), 'usable' => BigDecimal::zero(), 'quarantine' => BigDecimal::zero()]);
            $rate = $rates->get($item->id);
            $dailyRate = $rate['daily_rate'] ?? BigDecimal::zero();
            $rateDays = $rate['days'] ?? $this->rates->windowDays();

            $orderNeed = $orders->get($item->id, ['quantity' => BigDecimal::zero()])['quantity'];
            $plan = $plans->get($item->id, ['quantity' => BigDecimal::zero(), 'needed_by' => null]);
            $upcoming = $orderNeed->plus($plan['quantity']);

            $neededBy = null;
            if ($orderNeed->isPositive()) {
                $neededBy = $today; // an approved batch may start any moment
            } elseif ($plan['needed_by'] !== null) {
                $neededBy = CarbonImmutable::parse($plan['needed_by'])->startOfDay();
            } elseif ($plan['quantity']->isPositive()) {
                $neededBy = $today->addDays($horizon);
            }

            $horizonDemand = $dailyRate->multipliedBy($horizon);
            $demand = $upcoming->isGreaterThan($horizonDemand) ? $upcoming : $horizonDemand;

            $vendor = $vendors->get($item->id);
            [$lead, $leadSource] = match (true) {
                $item->lead_time_days !== null && $item->lead_time_days > 0 => [(int) $item->lead_time_days, 'item'],
                $vendor !== null && $vendor['lead_time_days'] !== null => [(int) $vendor['lead_time_days'], 'vendor'],
                default => [$defaultLead, 'default'],
            };

            $safety = $item->minimum_stock !== null ? BigDecimal::of($item->minimum_stock) : null;
            $reorder = $item->reorder_level !== null ? BigDecimal::of($item->reorder_level) : null;
            $maximum = $item->maximum_stock !== null ? BigDecimal::of($item->maximum_stock) : null;
            $pending = $onOrder->get($item->id, BigDecimal::zero());

            $usable = $b['usable'];
            $daysOfCover = null;
            $runsOut = null;

            if ($dailyRate->isPositive()) {
                $daysOfCover = (int) $usable->dividedBy($dailyRate, 0, RoundingMode::Down)->toInt();
                $runsOut = $today->addDays($daysOfCover);
            } elseif ($upcoming->isPositive() && $usable->isLessThan($upcoming)) {
                $runsOut = $neededBy;
            }

            $shortfall = $demand->plus($safety ?? BigDecimal::zero())->minus($usable)->minus($pending);
            $shortfall = $shortfall->isPositive() ? $shortfall : BigDecimal::zero();

            $recommended = $this->roundToOrderTerms($shortfall, $item, $usable->plus($pending), $maximum);

            // When must it be ordered so it lands before it is needed or runs out?
            $deadline = collect([$neededBy, $runsOut])->filter()->min();
            $orderBy = $recommended->isPositive()
                ? ($deadline instanceof CarbonImmutable ? $deadline->subDays($lead) : $today)
                : null;

            $status = match (true) {
                ! $recommended->isPositive() && $reorder !== null && $usable->isLessThanOrEqualTo($reorder) && $usable->isPositive() => ItemOutlook::WATCH,
                ! $recommended->isPositive() => ItemOutlook::OK,
                $orderBy === null || $orderBy->lessThanOrEqualTo($today) => ItemOutlook::ORDER_TODAY,
                $orderBy->lessThanOrEqualTo($today->addDays($soonDays)) => ItemOutlook::ORDER_SOON,
                default => ItemOutlook::WATCH,
            };

            return new ItemOutlook(
                itemId: $item->id,
                code: $item->code,
                name: $item->name,
                type: $item->type instanceof ItemType ? $item->type->value : (string) $item->type,
                unit: $item->stockUom?->code ?? '',
                onHand: $b['on_hand'],
                reserved: $b['reserved'],
                usable: $usable,
                inQuarantine: $b['quarantine'],
                dailyRate: $dailyRate,
                rateDays: $rateDays,
                upcomingRequirement: $upcoming,
                horizonDemand: $horizonDemand,
                onOrder: $pending,
                safetyStock: $safety,
                reorderLevel: $reorder,
                maximumStock: $maximum,
                leadTimeDays: $lead,
                leadTimeSource: $leadSource,
                daysOfCover: $daysOfCover,
                runsOutAt: $runsOut,
                neededBy: $neededBy,
                shortfall: $shortfall,
                recommendedQuantity: $recommended,
                orderBy: $orderBy,
                status: $status,
                vendor: $vendor,
                asOf: $asOf,
            );
        })->values();
    }

    /**
     * A shortfall becomes an order the supplier will accept: at least the
     * minimum order quantity, in whole packs, and not so much that the
     * store overflows its maximum unless the shortfall itself demands it.
     */
    private function roundToOrderTerms(BigDecimal $shortfall, Item $item, BigDecimal $alreadyCovered, ?BigDecimal $maximum): BigDecimal
    {
        if (! $shortfall->isPositive()) {
            return BigDecimal::zero();
        }

        $quantity = $shortfall;

        if ($item->min_order_quantity !== null && $quantity->isLessThan(BigDecimal::of($item->min_order_quantity))) {
            $quantity = BigDecimal::of($item->min_order_quantity);
        }

        if ($item->order_multiple !== null && BigDecimal::of($item->order_multiple)->isPositive()) {
            $multiple = BigDecimal::of($item->order_multiple);
            $packs = $quantity->dividedBy($multiple, 0, RoundingMode::Ceiling);
            $quantity = $multiple->multipliedBy($packs);
        }

        if ($maximum !== null) {
            $room = $maximum->minus($alreadyCovered);
            if ($room->isLessThan($quantity) && $room->isGreaterThanOrEqualTo($shortfall)) {
                $quantity = $room;
            }
        }

        return Decimal::stripped($quantity, 6);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>|null  $storeIds
     * @return Collection<int, array{on_hand: BigDecimal, reserved: BigDecimal, usable: BigDecimal, quarantine: BigDecimal}>
     */
    private function balances(array $ids, ?array $storeIds, CarbonImmutable $today): Collection
    {
        $rows = DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->whereIn('b.item_id', $ids)
            ->when($storeIds !== null, fn ($q) => $q->whereIn('b.warehouse_id', $storeIds))
            ->selectRaw('b.item_id, b.on_hand, b.reserved, w.is_quarantine, l.qc_status, l.expiry_at')
            ->get();

        $releasable = [LotQcStatus::Approved->value, LotQcStatus::NotRequired->value];

        return $rows->groupBy('item_id')->map(function (Collection $group) use ($releasable, $today): array {
            $onHand = BigDecimal::zero();
            $reserved = BigDecimal::zero();
            $usable = BigDecimal::zero();
            $quarantine = BigDecimal::zero();

            foreach ($group as $row) {
                $qty = BigDecimal::of($row->on_hand);
                $res = BigDecimal::of($row->reserved);
                $onHand = $onHand->plus($qty);
                $reserved = $reserved->plus($res);

                if ($row->is_quarantine) {
                    $quarantine = $quarantine->plus($qty);

                    continue;
                }

                $lotOk = $row->qc_status === null
                    || (in_array($row->qc_status, $releasable, true)
                        && ($row->expiry_at === null || CarbonImmutable::parse($row->expiry_at)->greaterThan($today)));

                if ($lotOk) {
                    $usable = $usable->plus($qty->minus($res));
                }
            }

            return ['on_hand' => $onHand, 'reserved' => $reserved, 'usable' => $usable, 'quarantine' => $quarantine];
        });
    }

    /**
     * What approved and running batches still need beyond what is reserved
     * for them.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array{quantity: BigDecimal}>
     */
    private function orderDemand(array $ids, ?Facility $facility): Collection
    {
        $rows = DB::table('manufacturing_order_lines as l')
            ->join('manufacturing_orders as o', 'o.id', '=', 'l.manufacturing_order_id')
            ->whereNull('o.deleted_at')
            ->whereIn('l.item_id', $ids)
            ->whereIn('o.status', [ManufacturingOrderStatus::Approved->value, ManufacturingOrderStatus::InProgress->value])
            ->when($facility, fn ($q) => $q->where('o.facility_id', $facility->id))
            ->selectRaw('l.item_id, SUM(GREATEST(l.planned_quantity - GREATEST(l.reserved_quantity, l.consumed_quantity), 0)) AS quantity')
            ->groupBy('l.item_id')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->item_id => ['quantity' => BigDecimal::of($r->quantity)]]);
    }

    /**
     * What checked and requested plans will need once they become batches.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array{quantity: BigDecimal, needed_by: string|null}>
     */
    private function planDemand(array $ids, ?Facility $facility): Collection
    {
        $rows = DB::table('production_plan_lines as l')
            ->join('production_plans as p', 'p.id', '=', 'l.production_plan_id')
            ->whereNull('p.deleted_at')
            ->whereIn('l.item_id', $ids)
            ->whereIn('p.status', [ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
            ->when($facility, fn ($q) => $q->where('p.facility_id', $facility->id))
            ->selectRaw('l.item_id, SUM(l.required_quantity) AS quantity, MIN(p.planned_start_date) AS needed_by')
            ->groupBy('l.item_id')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->item_id => ['quantity' => BigDecimal::of($r->quantity), 'needed_by' => $r->needed_by]]);
    }

    /**
     * Already asked of purchase and not yet delivered.
     *
     * @param  list<int>  $ids
     * @param  list<int>|null  $storeIds
     * @return Collection<int, BigDecimal>
     */
    private function onOrder(array $ids, ?array $storeIds): Collection
    {
        $rows = DB::table('material_request_lines as l')
            ->join('material_requests as r', 'r.id', '=', 'l.material_request_id')
            ->whereIn('l.item_id', $ids)
            ->whereIn('r.status', [MaterialRequestStatus::Open->value, MaterialRequestStatus::PartiallyReceived->value])
            ->when($storeIds !== null, fn ($q) => $q->whereIn('r.warehouse_id', $storeIds))
            ->selectRaw('l.item_id, SUM(GREATEST(l.quantity_to_order - l.received_quantity, 0)) AS quantity')
            ->groupBy('l.item_id')
            ->get();

        return $rows->mapWithKeys(fn ($r) => [(int) $r->item_id => BigDecimal::of($r->quantity)]);
    }

    /**
     * Who to buy from: the supplier with the best recent price per stock
     * unit, with their last price, how long they take, and how often they
     * have delivered. Lead time is measured from the material request to
     * the receipt where both exist, else taken from the vendor's card.
     *
     * @param  list<int>  $ids
     * @return Collection<int, array{id: int|null, name: string|null, last_price: string|null, best_price: string|null, best_vendor: string|null, lead_time_days: int|null, deliveries: int, last_delivery_at: string|null, last_vendor: string|null}>
     */
    public function vendorAdvice(array $ids, ?CarbonImmutable $asOf = null): Collection
    {
        $asOf ??= CarbonImmutable::now();
        $since = $asOf->subDays((int) config('erp.intelligence.price_history_days', 365));

        $lines = DB::table('goods_receipt_lines as l')
            ->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')
            ->join('vendors as v', 'v.id', '=', 'g.vendor_id')
            ->leftJoin('material_requests as r', 'r.id', '=', 'g.material_request_id')
            ->whereIn('l.item_id', $ids)
            ->where('g.status', GoodsReceiptStatus::Received->value)
            ->whereNotNull('g.posted_at')
            ->where('g.posted_at', '>=', $since)
            ->whereNotNull('l.unit_price')
            ->where('l.stock_quantity', '>', 0)
            ->selectRaw('l.item_id, v.id AS vendor_id, v.name AS vendor_name, v.lead_time_days AS vendor_lead, g.posted_at, r.requested_at, (l.unit_price * l.quantity / l.stock_quantity) AS price')
            ->orderBy('g.posted_at')
            ->get();

        return $lines->groupBy('item_id')->map(function (Collection $rows): array {
            $byVendor = $rows->groupBy('vendor_id')->map(function (Collection $deliveries) {
                $last = $deliveries->last();
                $leads = $deliveries
                    ->filter(fn ($d) => $d->requested_at !== null)
                    ->map(fn ($d) => max(0, (int) round(CarbonImmutable::parse($d->requested_at)->diffInDays(CarbonImmutable::parse($d->posted_at), true))));

                return [
                    'id' => (int) $last->vendor_id,
                    'name' => $last->vendor_name,
                    'last_price' => (string) BigDecimal::of($last->price)->toScale(4, RoundingMode::HalfUp),
                    'best_price' => (string) $deliveries->map(fn ($d) => BigDecimal::of($d->price))->reduce(fn (?BigDecimal $c, BigDecimal $p) => $c === null || $p->isLessThan($c) ? $p : $c)?->toScale(4, RoundingMode::HalfUp),
                    'lead_time_days' => $leads->isNotEmpty() ? (int) round($leads->avg()) : ($last->vendor_lead !== null ? (int) $last->vendor_lead : null),
                    'deliveries' => $deliveries->count(),
                    'last_delivery_at' => CarbonImmutable::parse($last->posted_at)->toDateString(),
                ];
            })->values();

            $best = $byVendor->sortBy([
                fn ($a, $b) => BigDecimal::of($a['best_price'])->compareTo(BigDecimal::of($b['best_price'])),
                fn ($a, $b) => ($a['lead_time_days'] ?? PHP_INT_MAX) <=> ($b['lead_time_days'] ?? PHP_INT_MAX),
            ])->first();
            $latest = $byVendor->sortByDesc('last_delivery_at')->first();

            return [
                ...$best,
                'best_vendor' => $best['name'],
                'last_vendor' => $latest['name'],
                'last_price' => $latest['last_price'],
            ];
        });
    }
}
