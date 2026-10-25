<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Models\LabelBatch;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentPick;
use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\Marketplace\Services\OnlineOrderAccess;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Support\Cutoff;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Online orders → Catch up: parcels whose labels were printed and which
 * went out with the courier without being scanned. Ticked ones are packed
 * and dispatched in one go, dated the day their labels were for, so the
 * stock and the dispatch record match what really happened.
 */
class CatchUpController extends Controller
{
    private const int MOST = 5000;

    public function __construct(
        private readonly OnlineOrderService $orders,
        private readonly BrandAccess $brands,
        private readonly OnlineOrderAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeCatchUp($user);

        $today = Cutoff::today();
        $from = $this->date($request->string('from')->toString()) ?? $today->startOfMonth();
        $to = $this->date($request->string('to')->toString()) ?? $today;

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $shipments = $this->visible($user)
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->whereHas('batch', fn (Builder $q) => $q->whereDate('for_date', '>=', $from->toDateString())->whereDate('for_date', '<=', $to->toDateString()))
            ->with(['batch:id,for_date', 'marketplace:id,name', 'brand:id,name', 'picks.item:id,code,name', 'lines:id,shipment_id,seller_sku,quantity'])
            ->orderBy('id')
            ->limit(self::MOST + 1)
            ->get();

        return Inertia::render('online-orders/catch-up', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'more' => $shipments->count() > self::MOST,
            'parcels' => $shipments->take(self::MOST)->map(fn (Shipment $s) => [
                'id' => $s->id,
                'day' => $s->batch?->for_date?->toDateString(),
                'awb' => $s->awb,
                'order_number' => $s->order_number,
                'marketplace' => $s->marketplace?->name,
                'brand' => $s->brand?->name,
                'courier' => $s->courier,
                'status' => $s->status->value,
                'status_label' => $s->status->label(),
                'stock_state' => $s->stock_state->value,
                'stock_label' => $s->stock_state->label(),
                'contents' => $s->picks->isNotEmpty()
                    ? $s->picks->map(fn (ShipmentPick $p) => ($p->item?->name ?? '?').' × '.Decimal::strip((string) $p->units))->implode(' + ')
                    : $s->lines->map(fn ($l) => "{$l->seller_sku} × {$l->quantity}")->implode(', '),
                'pieces' => $s->picks->map(fn (ShipmentPick $p) => ['item' => $p->item?->name ?? '?', 'units' => (float) $p->units])->values()->all(),
            ])->values()->all(),
        ]);
    }

    /**
     * One run of ticked parcels; the screen sends them a hundred at a time.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->authorizeCatchUp($user);

        $data = $request->validate([
            'shipment_ids' => ['required', 'array', 'min:1', 'max:200'],
            'shipment_ids.*' => ['integer'],
        ]);

        return response()->json($this->orders->catchUp($this->visible($user), array_map('intval', $data['shipment_ids']), $user));
    }

    private function authorizeCatchUp(User $user): void
    {
        abort_unless(
            ($user->can('marketplace.pack') && $user->can('marketplace.handover')) || $user->can('marketplace.manage'),
            403,
        );
        abort_if($this->brands->isRestricted($user), 403);
    }

    /**
     * @return Builder<Shipment>
     */
    private function visible(User $user): Builder
    {
        $batches = $this->brands->scopeByBrand($user, LabelBatch::query());
        $ids = $this->access->facilityIds($user);

        if ($ids !== null) {
            $batches->whereIn('facility_id', $ids);
        }

        return Shipment::query()->whereIn('label_batch_id', $batches->select('id'));
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, Cutoff::timezone())->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
