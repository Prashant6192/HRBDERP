<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Contract\Enums\ManufacturingType;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\Contract\Services\JobCostingService;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Intelligence\DTOs\ItemOutlook;
use App\Domain\Intelligence\Services\CommandCentreService;
use App\Domain\Intelligence\Services\StockOutlookService;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Models\User;
use App\Support\Math\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The factory on a phone, for the people who run it: what is being made,
 * what is on the shelf and what it is worth, what to order, which recipes
 * are live, who the third-party clients are, which batches are ready,
 * what is still to be billed, and how each batch's pack must look.
 *
 * Everything here is read straight from the same tables the floor writes
 * to; nothing is entered on these screens.
 */
class ManagementDashboardService
{
    public function __construct(
        private readonly FacilityAccess $access,
        private readonly CommandCentreService $centre,
        private readonly StockOutlookService $outlook,
        private readonly JobCostingService $costing,
        private readonly ArtworkService $artworks,
    ) {}

    // ---- Overview -------------------------------------------------------

    /**
     * One number per question, for the home screen.
     *
     * @return array<string, mixed>
     */
    public function overview(User $user): array
    {
        $facility = $this->facilityFor($user);
        $ids = $this->facilityIds($user);
        $now = CarbonImmutable::now();

        $orders = ManufacturingOrder::query()->when($ids !== null, fn ($q) => $q->whereIn('facility_id', $ids));
        $value = $this->stockValueByType($ids);
        $ready = $this->readyBatches($user);
        $billing = $this->billing($user);
        $advice = $this->outlook->reorderAdvice($facility);

        return [
            'as_of' => $now->toIso8601String(),
            'production' => [
                'in_progress' => (clone $orders)->where('status', ManufacturingOrderStatus::InProgress->value)->count(),
                'approved' => (clone $orders)->where('status', ManufacturingOrderStatus::Approved->value)->count(),
                'completed_this_month' => (clone $orders)->where('status', ManufacturingOrderStatus::Completed->value)->where('completed_at', '>=', $now->startOfMonth())->count(),
                'awaiting_qc' => count($this->centre->awaitingQc($facility, $now)),
            ],
            'stock' => [
                'raw_material_value' => $value[ItemType::RawMaterial->value]['value'] ?? '0.00',
                'packaging_value' => $value[ItemType::PackagingMaterial->value]['value'] ?? '0.00',
                'finished_goods_value' => $value[ItemType::FinishedGood->value]['value'] ?? '0.00',
                'raw_material_items' => $value[ItemType::RawMaterial->value]['items'] ?? 0,
                'quarantine_value' => $this->quarantineValue($ids),
            ],
            'ordering' => [
                'order_today' => $advice->where('status', ItemOutlook::ORDER_TODAY)->count(),
                'order_soon' => $advice->where('status', ItemOutlook::ORDER_SOON)->count(),
                'watch' => $advice->where('status', ItemOutlook::WATCH)->count(),
            ],
            'formulas' => [
                'active' => Formula::query()->active()->count(),
                'total' => Formula::query()->count(),
            ],
            'clients' => [
                'active' => Client::query()->where('is_active', true)->count(),
                'open_jobs' => (clone $orders)->open()->where('manufacturing_type', ManufacturingType::ThirdParty->value)->count(),
            ],
            'batches' => [
                'ready' => count($ready['batches']),
                'ready_units' => $ready['total_units'],
            ],
            'billing' => [
                'jobs' => count($billing['jobs']),
                'jobs_total' => $billing['jobs_total'],
                'dispatches' => count($billing['dispatches']),
                'dispatches_total' => $billing['dispatches_total'],
                'total' => $billing['total'],
            ],
        ];
    }

    // ---- Sections -------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function production(User $user): array
    {
        $facility = $this->facilityFor($user);
        $now = CarbonImmutable::now();
        $ids = $this->facilityIds($user);

        $recent = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('facility_id', $ids))
            ->with(['product:id,name', 'client:id,name', 'plannedUom:id,code', 'outputLot:id,batch_number,qc_status'])
            ->orderByDesc('completed_at')
            ->limit(15)
            ->get()
            ->map(fn (ManufacturingOrder $o) => $this->completedRow($o))
            ->all();

        return [
            'running' => $this->centre->running($facility, $now),
            'awaiting_qc' => $this->centre->awaitingQc($facility, $now),
            'recent' => $recent,
        ];
    }

    /**
     * What is on the shelf, by material, with what it is worth.
     *
     * @return array<string, mixed>
     */
    public function materials(User $user, string $type = 'raw_material'): array
    {
        $ids = $this->facilityIds($user);
        $type = ItemType::tryFrom($type) ?? ItemType::RawMaterial;

        $rows = $this->stockRows($ids)
            ->where('type', $type->value)
            ->sortByDesc(fn (array $r) => (float) $r['value'])
            ->values();

        return [
            'type' => $type->value,
            'type_label' => $type->label(),
            'types' => array_map(fn (ItemType $t) => ['value' => $t->value, 'label' => $t->label()], [ItemType::RawMaterial, ItemType::PackagingMaterial, ItemType::FinishedGood, ItemType::Consumable]),
            'items' => $rows->all(),
            'total_value' => $this->sum($rows->pluck('value')),
            'quarantine_value' => $this->sum($rows->pluck('quarantine_value')),
            'by_type' => $this->stockValueByType($ids),
        ];
    }

    /**
     * What to order, in the order it needs deciding.
     *
     * @return array<string, mixed>
     */
    public function ordering(User $user): array
    {
        $advice = $this->outlook->reorderAdvice($this->facilityFor($user));
        $rank = [ItemOutlook::ORDER_TODAY => 0, ItemOutlook::ORDER_SOON => 1, ItemOutlook::WATCH => 2];

        return [
            'items' => $advice
                ->sortBy(fn (ItemOutlook $o) => ($rank[$o->status] ?? 3).'-'.($o->orderBy?->toDateString() ?? '9999').'-'.$o->name)
                ->map(fn (ItemOutlook $o) => $o->toArray())
                ->values()
                ->all(),
        ];
    }

    /**
     * The recipes that are live, and whose they are.
     *
     * @return array<string, mixed>
     */
    public function formulas(): array
    {
        return [
            'formulas' => Formula::query()
                ->with(['product:id,code,name', 'activeVersion:id,version_number,updated_at', 'client:id,name'])
                ->withCount('versions')
                ->orderBy('name')
                ->get()
                ->map(fn (Formula $f) => [
                    'id' => $f->id,
                    'code' => $f->code,
                    'name' => $f->name,
                    'product' => $f->product?->name,
                    'status' => $f->status->value,
                    'status_label' => $f->status->label(),
                    'ownership' => $f->ownership->value,
                    'ownership_label' => $f->ownership->label(),
                    'client' => $f->client?->name,
                    'version' => $f->activeVersion?->version_number,
                    'versions' => $f->versions_count,
                    'updated_at' => ($f->activeVersion?->updated_at ?? $f->updated_at)?->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * Who the company manufactures for, and how much is in hand for each.
     *
     * @return array<string, mixed>
     */
    public function clients(): array
    {
        return [
            'clients' => Client::query()
                ->withCount([
                    'products',
                    'orders as open_jobs' => fn ($q) => $q->open(),
                    'orders as running_jobs' => fn ($q) => $q->where('status', ManufacturingOrderStatus::InProgress->value),
                    'orders as completed_jobs' => fn ($q) => $q->where('status', ManufacturingOrderStatus::Completed->value),
                ])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(fn (Client $c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'name' => $c->name,
                    'city' => $c->billing_city,
                    'contact' => $c->contact_person,
                    'phone' => $c->phone,
                    'is_active' => (bool) $c->is_active,
                    'products' => (int) $c->products_count,
                    'open_jobs' => (int) $c->open_jobs,
                    'running_jobs' => (int) $c->running_jobs,
                    'completed_jobs' => (int) $c->completed_jobs,
                    'payment_terms_days' => $c->payment_terms_days,
                    'last_completed_at' => $c->orders()->where('status', ManufacturingOrderStatus::Completed->value)->max('completed_at'),
                ])
                ->all(),
        ];
    }

    /**
     * Finished batches that passed QC and are on the shelf, with the
     * artwork each was packed to.
     *
     * @return array{batches: list<array<string, mixed>>, total_units: string}
     */
    public function readyBatches(User $user): array
    {
        $ids = $this->facilityIds($user);

        $rows = DB::table('stock_balances as b')
            ->join('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.stock_uom_id')
            ->leftJoin('clients as c', 'c.id', '=', 'l.owner_client_id')
            ->where('i.type', ItemType::FinishedGood->value)
            ->where('l.qc_status', LotQcStatus::Approved->value)
            ->where('w.is_quarantine', false)
            ->whereNull('w.deleted_at')
            ->where('b.on_hand', '>', 0)
            ->when($ids !== null, fn ($q) => $q->whereIn('w.facility_id', $ids))
            ->groupBy('l.id', 'l.batch_number', 'l.expiry_at', 'l.manufactured_at', 'i.id', 'i.code', 'i.name', 'u.code', 'c.name')
            ->orderByDesc('l.id')
            ->selectRaw('l.id AS lot_id, l.batch_number, l.expiry_at, l.manufactured_at, i.id AS item_id, i.code, i.name, u.code AS unit, c.name AS client, SUM(b.on_hand) AS on_hand, STRING_AGG(DISTINCT w.code, \', \') AS stores')
            ->get();

        $orders = ManufacturingOrder::query()
            ->whereIn('output_lot_id', $rows->pluck('lot_id')->all())
            ->with(['plannedUom:id,code'])
            ->get()
            ->keyBy('output_lot_id');

        $batches = $rows->map(function (object $r) use ($orders): array {
            /** @var ManufacturingOrder|null $order */
            $order = $orders->get($r->lot_id);
            $artworks = $order !== null
                ? array_values(array_filter($this->artworks->forBatch($order), fn (array $a) => $a['status'] === 'approved'))
                : $this->artworks->forProduct((int) $r->item_id, null)->filter(fn ($a) => $a->status->value === 'approved')->map(fn ($a) => $this->artworks->serialize($a))->values()->all();

            return [
                'lot_id' => $r->lot_id,
                'batch_number' => $r->batch_number,
                'product' => $r->name,
                'product_code' => $r->code,
                'client' => $r->client,
                'on_hand' => Decimal::strip((string) $r->on_hand),
                'unit' => $r->unit,
                'stores' => $r->stores,
                'manufactured_at' => $r->manufactured_at,
                'expiry_at' => $r->expiry_at,
                'order' => $order === null ? null : [
                    'id' => $order->id,
                    'number' => $order->number,
                    'planned' => Decimal::strip($order->planned_quantity).' '.($order->plannedUom?->code ?? ''),
                    'planned_units' => $order->planned_units,
                    'output_units' => $order->output_units,
                    'rejected_units' => $order->rejected_units,
                    'yield' => $order->yield_percentage === null ? null : Decimal::strip($order->yield_percentage),
                    'overall_yield' => $order->overall_yield_percentage === null ? null : Decimal::strip($order->overall_yield_percentage),
                ],
                'artworks' => $artworks,
                'href' => route('lots.show', $r->lot_id),
            ];
        })->values();

        return [
            'batches' => $batches->all(),
            'total_units' => $this->sum($batches->pluck('on_hand'), 0),
        ];
    }

    /**
     * What has been made or written up and not yet invoiced: third-party
     * jobs whose batch has not gone out, and consignments still in draft.
     *
     * @return array<string, mixed>
     */
    public function billing(User $user): array
    {
        $ids = $this->facilityIds($user);

        $dispatchedLots = DB::table('dispatch_lines as dl')
            ->join('dispatches as d', 'd.id', '=', 'dl.dispatch_id')
            ->where('d.status', '!=', DispatchStatus::Cancelled->value)
            ->whereNull('d.deleted_at')
            ->pluck('dl.lot_id');

        $jobs = ManufacturingOrder::query()
            ->where('status', ManufacturingOrderStatus::Completed->value)
            ->where('manufacturing_type', ManufacturingType::ThirdParty->value)
            ->whereNotNull('client_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('facility_id', $ids))
            ->where(fn ($q) => $q->whereNull('output_lot_id')->orWhereNotIn('output_lot_id', $dispatchedLots->all()))
            ->with(['product:id,name', 'client:id,name', 'plannedUom:id,code', 'outputLot:id,batch_number'])
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get()
            ->map(function (ManufacturingOrder $o): array {
                $cost = $this->costing->forOrder($o);

                return [
                    ...$this->completedRow($o),
                    'manufacturing_charge' => $cost['manufacturing_charge'] ?? '0.00',
                    'material_charge' => $cost['material_charge'] ?? '0.00',
                    'gst' => $cost['gst'] ?? '0.00',
                    'total' => $cost['total'] ?? '0.00',
                    'has_terms' => ($o->charges ?? []) !== [],
                ];
            });

        $dispatches = Dispatch::query()
            ->where('status', DispatchStatus::Draft->value)
            ->when($ids !== null, fn ($q) => $q->whereIn('facility_id', $ids))
            ->with('customer:id,name')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Dispatch $d) => [
                'id' => $d->id,
                'number' => $d->number,
                'customer' => $d->customer?->name,
                'lines' => $d->lines()->count(),
                'total' => (string) $d->total_value,
                'created_at' => $d->created_at?->toIso8601String(),
                'href' => route('dispatches.show', $d),
            ]);

        $jobsTotal = $this->sum($jobs->pluck('total'));
        $dispatchesTotal = $this->sum($dispatches->pluck('total'));

        return [
            'jobs' => $jobs->values()->all(),
            'jobs_total' => $jobsTotal,
            'dispatches' => $dispatches->values()->all(),
            'dispatches_total' => $dispatchesTotal,
            'total' => BigDecimal::of($jobsTotal)->plus($dispatchesTotal)->toScale(2, RoundingMode::HalfUp)->__toString(),
        ];
    }

    // ---- Internals ------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function completedRow(ManufacturingOrder $o): array
    {
        return [
            'id' => $o->id,
            'number' => $o->number,
            'product' => $o->product?->name,
            'client' => $o->client?->name,
            'planned' => Decimal::strip($o->planned_quantity).' '.($o->plannedUom?->code ?? ''),
            'output' => $o->output_quantity === null ? null : Decimal::strip($o->output_quantity).' '.($o->plannedUom?->code ?? ''),
            'planned_units' => $o->planned_units,
            'output_units' => $o->output_units,
            'rejected_units' => $o->rejected_units,
            'yield' => $o->yield_percentage === null ? null : Decimal::strip($o->yield_percentage),
            'overall_yield' => $o->overall_yield_percentage === null ? null : Decimal::strip($o->overall_yield_percentage),
            'batch' => $o->outputLot?->batch_number,
            'completed_at' => $o->completed_at?->toIso8601String(),
            'href' => route('manufacturing.show', $o),
        ];
    }

    /**
     * Every material with stock, its quantity and value: batch cost where
     * the batch carries one, the standard cost otherwise.
     *
     * @param  list<int>|null  $facilityIds
     * @return Collection<int, array<string, mixed>>
     */
    private function stockRows(?array $facilityIds): Collection
    {
        return DB::table('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'i.stock_uom_id')
            ->where('b.on_hand', '>', 0)
            ->whereNull('w.deleted_at')
            ->whereNull('i.deleted_at')
            ->when($facilityIds !== null, fn ($q) => $q->whereIn('w.facility_id', $facilityIds))
            ->groupBy('i.id', 'i.code', 'i.name', 'i.type', 'u.code', 'i.reorder_level')
            ->selectRaw(<<<'SQL'
                i.id AS item_id, i.code, i.name, i.type, u.code AS unit, i.reorder_level,
                SUM(b.on_hand) AS on_hand,
                SUM(b.reserved) AS reserved,
                SUM(CASE WHEN w.is_quarantine THEN b.on_hand ELSE 0 END) AS in_quarantine,
                SUM(b.on_hand * COALESCE(l.unit_cost, i.standard_cost, 0)) AS value,
                SUM(CASE WHEN w.is_quarantine THEN b.on_hand * COALESCE(l.unit_cost, i.standard_cost, 0) ELSE 0 END) AS quarantine_value,
                COUNT(DISTINCT b.lot_id) AS batches
            SQL)
            ->get()
            ->map(fn (object $r) => [
                'item_id' => $r->item_id,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'unit' => $r->unit,
                'on_hand' => Decimal::strip((string) $r->on_hand),
                'reserved' => Decimal::strip((string) $r->reserved),
                'in_quarantine' => Decimal::strip((string) $r->in_quarantine),
                'value' => BigDecimal::of((string) $r->value)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'quarantine_value' => BigDecimal::of((string) $r->quarantine_value)->toScale(2, RoundingMode::HalfUp)->__toString(),
                'batches' => (int) $r->batches,
                'below_reorder' => $r->reorder_level !== null && BigDecimal::of((string) $r->on_hand)->isLessThan(BigDecimal::of((string) $r->reorder_level)),
                'href' => $this->itemHref((string) $r->type, (int) $r->item_id),
            ]);
    }

    /**
     * @param  list<int>|null  $facilityIds
     * @return array<string, array{value: string, items: int}>
     */
    private function stockValueByType(?array $facilityIds): array
    {
        return $this->stockRows($facilityIds)
            ->groupBy('type')
            ->map(fn (Collection $rows) => ['value' => $this->sum($rows->pluck('value')), 'items' => $rows->count()])
            ->all();
    }

    /**
     * @param  list<int>|null  $facilityIds
     */
    private function quarantineValue(?array $facilityIds): string
    {
        return $this->sum($this->stockRows($facilityIds)->pluck('quarantine_value'));
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function sum(Collection $values, int $scale = 2): string
    {
        return $values->reduce(fn (BigDecimal $carry, $v) => $carry->plus(BigDecimal::of((string) ($v ?? '0'))), BigDecimal::zero())
            ->toScale($scale, RoundingMode::HalfUp)->__toString();
    }

    private function itemHref(string $type, int $id): string
    {
        return match ($type) {
            ItemType::RawMaterial->value, ItemType::Consumable->value => route('raw-materials.show', $id),
            ItemType::PackagingMaterial->value => route('packaging-materials.show', $id),
            default => route('products.show', $id),
        };
    }

    private function facilityFor(User $user): ?Facility
    {
        return $this->access->isCompanyWide($user) ? null : $this->access->primaryFacility($user);
    }

    /**
     * @return list<int>|null
     */
    private function facilityIds(User $user): ?array
    {
        return $this->access->isCompanyWide($user) ? null : ($this->access->facilityIds($user) ?? []);
    }
}
