<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Marketplace\Enums\LabelBatchStatus;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Enums\StockState;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The depot's first screen: today's online orders.
 *
 * The agency uploads the day's labels and closes each upload when it is
 * done; until then the depot waits. Once every upload of the day is
 * closed, the parcels are ready and the screen says so, loudly, with one
 * button to start. Around it: the lorry on its way from the factory, the
 * finished goods running low, and dispatches waiting for an invoice.
 */
class DepotDashboardService
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user, Facility $facility): array
    {
        $day = Cutoff::today();

        return [
            'date' => $day->toDateString(),
            'online' => $user->can('marketplace.view') ? $this->online($facility, $day->toDateString()) : null,
            'incoming' => $user->can('inventory.view') ? $this->incoming($facility) : null,
            'store' => $user->can('inventory.view') ? $this->store($facility) : null,
            'dispatches' => $user->can('dispatch.view') ? Dispatch::query()->where('facility_id', $facility->id)
                ->whereIn('status', [DispatchStatus::Draft->value, DispatchStatus::Invoiced->value])->count() : null,
        ];
    }

    /**
     * Today's uploads and where their parcels stand.
     *
     * @return array<string, mixed>
     */
    public function online(Facility $facility, string $day): array
    {
        $batches = LabelBatch::query()
            ->where('facility_id', $facility->id)
            ->whereDate('for_date', $day)
            ->with(['brand:id,name', 'marketplace:id,name', 'uploader:id,name', 'closer:id,name'])
            ->withCount([
                'shipments as total' => fn ($q) => $q->whereNotIn('status', [ShipmentStatus::Cancelled->value, ShipmentStatus::Returned->value]),
                'shipments as to_pack' => fn ($q) => $q->whereIn('status', ShipmentStatus::awaitingPacking()),
            ])
            ->orderBy('created_at')
            ->get();

        $parcels = fn (): Builder => Shipment::query()->whereIn('label_batch_id', $batches->pluck('id'));

        $count = fn (array $statuses): int => $batches->isEmpty() ? 0 : $parcels()->whereIn('status', $statuses)->count();

        $total = (int) $batches->sum('total');
        $toPack = (int) $batches->sum('to_pack');
        $open = $batches->where('status', LabelBatchStatus::Open)->count();

        $state = match (true) {
            $batches->isEmpty() => 'waiting',
            $open > 0 => 'uploading',
            $toPack > 0 => 'ready',
            default => 'done',
        };

        $couriers = $batches->isEmpty() ? collect() : $parcels()
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->selectRaw("coalesce(nullif(courier, ''), 'Courier not read') as courier, count(*) as n")
            ->groupBy('courier')->orderByDesc('n')->get();

        $lastClosed = $batches->whereNotNull('closed_at')->sortByDesc('closed_at')->first();

        return [
            'state' => $state,
            'total' => $total,
            'to_pack' => $toPack,
            'printed' => $count([ShipmentStatus::Printed->value]),
            'packed' => $count([ShipmentStatus::Packed->value, ShipmentStatus::HandedOver->value]),
            'handed_over' => $count([ShipmentStatus::HandedOver->value]),
            'attention' => $batches->isEmpty() ? 0 : $parcels()->whereIn('status', ShipmentStatus::awaitingPacking())
                ->where(fn ($q) => $q->whereIn('stock_state', [StockState::Unmapped->value, StockState::Short->value])->orWhereNull('awb'))->count(),
            'open_uploads' => $open,
            'uploaded_by' => $batches->map(fn (LabelBatch $b) => $b->uploader?->name)->filter()->unique()->values()->all(),
            'finished_at' => $open === 0 ? $lastClosed?->closed_at?->toIso8601String() : null,
            'batches' => $batches->map(fn (LabelBatch $b) => [
                'id' => $b->id,
                'number' => $b->number,
                'brand' => $b->brand?->name,
                'marketplace' => $b->marketplace?->name,
                'status' => $b->status->value,
                'total' => (int) $b->total,
                'to_pack' => (int) $b->to_pack,
                'uploaded_by' => $b->uploader?->name,
                'uploaded_at' => $b->created_at?->toIso8601String(),
                'closed_at' => $b->closed_at?->toIso8601String(),
            ])->values()->all(),
            'couriers' => $couriers->map(fn ($r) => ['courier' => $r->courier, 'n' => (int) $r->n])->values()->all(),
        ];
    }

    /**
     * Lorries on the road to the depot.
     *
     * @return array{count: int, next: array{number: string, from: string|null, dispatched_at: string|null}|null}
     */
    public function incoming(Facility $facility): array
    {
        $query = StockTransfer::query()->where('destination_facility_id', $facility->id)
            ->whereIn('status', [StockTransferStatus::Dispatched->value, StockTransferStatus::InTransit->value, StockTransferStatus::PartiallyReceived->value]);

        $next = (clone $query)->with('sourceFacility:id,name')->orderBy('dispatched_at')->first();

        return [
            'count' => $query->count(),
            'next' => $next === null ? null : [
                'number' => $next->number,
                'from' => $next->sourceFacility?->name,
                'dispatched_at' => $next->dispatched_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * Finished goods at the depot running low.
     *
     * @return array{items: int, critical: int, low: int}|null
     */
    public function store(Facility $facility): ?array
    {
        $fg = collect($this->dashboard->storeLevels($facility))->firstWhere('kind', 'finished_goods');

        if ($fg === null || $fg['warehouse_id'] === null) {
            return null;
        }

        return [
            'items' => (int) $fg['items'],
            'critical' => (int) ($fg['counts'][StockAlertLevel::Critical->value] ?? 0) + (int) ($fg['counts'][StockAlertLevel::OutOfStock->value] ?? 0),
            'low' => (int) ($fg['counts'][StockAlertLevel::Low->value] ?? 0),
        ];
    }
}
