<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Services;

use App\Domain\Approvals\Services\ApprovalService;
use App\Domain\Contract\Enums\ManufacturingType;
use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Enums\StockAlertLevel;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Quality\Models\QcInspection;
use App\Domain\Reporting\Services\DashboardService;
use App\Domain\Warehousing\Models\Facility;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The small numbers beside each page in a place's column: what is waiting
 * there now. A count the person may not see is left out, and a count that
 * cannot be worked out is left out rather than shown wrong.
 *
 * Tones: "bad" needs someone now, "warn" soon, "info" is simply a number.
 */
class NavigationCountService
{
    private const int STORE_CACHE_SECONDS = 60;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array{key: string, kind: string, facility_id: int|null}  $place
     * @return array<string, array{n: int, tone: string}>
     */
    public function for(User $user, array $place): array
    {
        $counts = [];
        $facility = $place['facility_id'] !== null ? Facility::query()->find($place['facility_id']) : null;

        $this->add($counts, 'approvals', fn () => $user->can('approval.view') ? $this->approvals->pendingFor($user)->count() : null, 'info');

        if ($place['kind'] === 'factory' && $facility !== null) {
            $this->add($counts, 'qc', fn () => $user->can('qc.view')
                ? QcInspection::query()->whereIn('status', [LotQcStatus::Pending->value, LotQcStatus::OnHold->value])->count()
                : null, 'warn');
            $this->stores($counts, $user, $facility);
            $this->add($counts, 'transfers.out', fn () => $user->can('inventory.view')
                ? StockTransfer::query()->where('source_facility_id', $facility->id)
                    ->whereIn('status', [StockTransferStatus::Requested->value, StockTransferStatus::Approved->value, StockTransferStatus::Packed->value])->count()
                : null, 'info');
            $this->add($counts, 'manufacturing', fn () => $user->can('production.view')
                ? ManufacturingOrder::query()->where('facility_id', $facility->id)->where('status', ManufacturingOrderStatus::InProgress->value)->count()
                : null, 'info');
        }

        if ($place['kind'] === 'depot' && $facility !== null) {
            $this->stores($counts, $user, $facility);
            $this->add($counts, 'transfers.in', fn () => $user->can('inventory.view')
                ? StockTransfer::query()->where('destination_facility_id', $facility->id)
                    ->whereIn('status', [StockTransferStatus::Dispatched->value, StockTransferStatus::InTransit->value, StockTransferStatus::PartiallyReceived->value])->count()
                : null, 'info');
            $this->add($counts, 'receive', fn () => $counts['transfers.in']['n'] ?? null, 'info');
            $this->add($counts, 'online', fn () => $user->can('marketplace.view')
                ? Shipment::query()->whereIn('status', ShipmentStatus::awaitingPacking())
                    ->whereHas('warehouse', fn ($q) => $q->where('facility_id', $facility->id))->count()
                : null, 'warn');
            $this->add($counts, 'dispatches', fn () => $user->can('dispatch.view')
                ? Dispatch::query()->where('facility_id', $facility->id)
                    ->whereIn('status', [DispatchStatus::Draft->value, DispatchStatus::Invoiced->value])->count()
                : null, 'info');
        }

        if ($place['kind'] === 'contract') {
            $this->add($counts, 'contract.jobs', fn () => $user->can('production.view')
                ? ManufacturingOrder::query()->where('manufacturing_type', ManufacturingType::ThirdParty->value)
                    ->whereIn('status', [ManufacturingOrderStatus::Approved->value, ManufacturingOrderStatus::InProgress->value])->count()
                : null, 'info');
        }

        return $counts;
    }

    /**
     * Materials low or critical in each of the facility's stores.
     *
     * @param  array<string, array{n: int, tone: string}>  $counts
     */
    private function stores(array &$counts, User $user, Facility $facility): void
    {
        if (! $user->can('inventory.view')) {
            return;
        }

        try {
            $levels = Cache::remember("nav.store-levels.{$facility->id}", self::STORE_CACHE_SECONDS, fn () => $this->dashboard->storeLevels($facility));
        } catch (Throwable) {
            return;
        }

        foreach ($levels as $store) {
            $critical = (int) ($store['counts'][StockAlertLevel::Critical->value] ?? 0) + (int) ($store['counts'][StockAlertLevel::OutOfStock->value] ?? 0);
            $low = (int) ($store['counts'][StockAlertLevel::Low->value] ?? 0);

            if ($store['warehouse_id'] !== null && $critical + $low > 0) {
                $counts['store.'.$store['kind']] = ['n' => $critical + $low, 'tone' => $critical > 0 ? 'bad' : 'warn'];
            }
        }
    }

    /**
     * @param  array<string, array{n: int, tone: string}>  $counts
     * @param  callable(): (int|null)  $count
     */
    private function add(array &$counts, string $key, callable $count, string $tone): void
    {
        try {
            $n = $count();
        } catch (Throwable) {
            return;
        }

        if ($n !== null && $n > 0) {
            $counts[$key] = ['n' => $n, 'tone' => $tone];
        }
    }
}
