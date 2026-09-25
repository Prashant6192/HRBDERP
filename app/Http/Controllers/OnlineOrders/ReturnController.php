<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Enums\ClaimStatus;
use App\Domain\Marketplace\Enums\ReturnKind;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentReturn;
use App\Domain\Marketplace\Models\ShipmentReturnLine;
use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Services\ReturnService;
use App\Domain\Marketplace\Support\Cutoff;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Online orders → Returns: parcels that went out and came back, what was
 * sellable, what was damaged, and the claims on the marketplaces.
 */
class ReturnController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly OnlineOrderService $orders,
        private readonly BrandAccess $brands,
        private readonly FacilityAccess $facilities,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->authorizeSeeing($user);

        $claim = $request->string('claim')->toString();
        $claim = in_array($claim, ['open', 'overdue', 'all'], true) ? $claim : 'all';
        $marketplaceId = $request->integer('marketplace') ?: null;
        $from = $this->date($request->string('from')->toString()) ?? Cutoff::today()->subDays(30);
        $to = $this->date($request->string('to')->toString()) ?? Cutoff::today();

        $query = $this->visible($user)
            ->when($marketplaceId, fn (Builder $q) => $q->where('marketplace_id', $marketplaceId))
            ->when($claim === 'open', fn (Builder $q) => $q->where('claim_status', ClaimStatus::Open->value))
            ->when($claim === 'overdue', fn (Builder $q) => $q->where('claim_status', ClaimStatus::Open->value)->where('claim_deadline_at', '<', now()))
            ->when($claim === 'all', fn (Builder $q) => $q
                ->where('received_at', '>=', $from->startOfDay()->utc())
                ->where('received_at', '<', $to->addDay()->startOfDay()->utc()));

        $returns = (clone $query)
            ->with(['shipment:id,awb,order_number,courier,label_batch_id', 'marketplace:id,name', 'brand:id,name', 'facility:id,name', 'receiver:id,name', 'lines.item:id,code,name', 'lines.goodStore:id,name', 'lines.damagedStore:id,name'])
            ->latest('received_at')
            ->limit(300)
            ->get();

        $summary = [
            'returns' => $returns->count(),
            'rto' => $returns->where('kind', ReturnKind::Rto)->count(),
            'customer' => $returns->where('kind', ReturnKind::Customer)->count(),
            'good' => Decimal::strip((string) $returns->flatMap->lines->sum(fn (ShipmentReturnLine $l) => (float) $l->good)),
            'damaged' => Decimal::strip((string) $returns->flatMap->lines->sum(fn (ShipmentReturnLine $l) => (float) $l->damaged)),
            'missing' => Decimal::strip((string) $returns->flatMap->lines->sum(fn (ShipmentReturnLine $l) => (float) $l->missing)),
            'claims_open' => $this->visible($user)->where('claim_status', ClaimStatus::Open->value)->count(),
            'claims_overdue' => $this->visible($user)->where('claim_status', ClaimStatus::Open->value)->where('claim_deadline_at', '<', now())->count(),
        ];

        return Inertia::render('online-orders/returns', [
            'returns' => $returns->map(fn (ShipmentReturn $r) => $this->present($r))->all(),
            'summary' => $summary,
            'filters' => [
                'claim' => $claim,
                'marketplace' => $marketplaceId,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'marketplaces' => Marketplace::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Marketplace $m) => ['value' => (string) $m->id, 'label' => $m->name])->all(),
            'claim_statuses' => array_map(fn (ClaimStatus $s) => ['value' => $s->value, 'label' => $s->label()], ClaimStatus::cases()),
            'can' => [
                'receive' => $user->can('marketplace.return'),
                'claim' => $user->can('marketplace.manage') || $user->can('marketplace.return'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('marketplace.return');
        $user = $request->user();

        return Inertia::render('online-orders/receive-return', [
            'code' => trim($request->string('code')->toString()),
            'kinds' => ReturnKind::options(),
            'stores' => $this->goodStores($user),
            'recent' => $this->visible($user)
                ->where('received_by', $user->id)
                ->with(['shipment:id,awb,order_number', 'marketplace:id,name', 'lines.item:id,code,name'])
                ->latest('received_at')
                ->limit(10)
                ->get()
                ->map(fn (ShipmentReturn $r) => $this->present($r))
                ->all(),
        ]);
    }

    /**
     * Find the parcel a returning packet belongs to, and what left in it.
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('marketplace.return');
        $user = $request->user();
        $code = trim($request->string('code')->toString());
        $shipment = $code === '' ? null : $this->orders->findByCode($code);

        if ($shipment === null || ($this->brands->isRestricted($user) && ! $this->brands->canUse($user, $shipment->brand_id))) {
            return response()->json(['message' => "No parcel has the code {$code}. Check the AWB or order number on the returning packet."], 404);
        }

        $sent = collect($this->returns->sent($shipment))
            ->map(fn (array $s, int $itemId) => [
                'item_id' => $itemId,
                'item' => $s['item']->name,
                'item_code' => $s['item']->code,
                'units' => Decimal::strip((string) $s['units']),
            ])
            ->values()
            ->all();

        return response()->json([
            'shipment' => OnlineOrderPresenter::shipment($shipment),
            'sent' => $sent,
            'why_not' => $this->returns->cannotReturn($shipment),
            'store_id' => $shipment->warehouse_id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.return');
        $user = $request->user();

        $data = $request->validate([
            'shipment_id' => ['required', 'integer', Rule::exists('shipments', 'id')],
            'kind' => ['required', Rule::enum(ReturnKind::class)],
            'into_store_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.good' => ['nullable', 'numeric', 'min:0'],
            'lines.*.damaged' => ['nullable', 'numeric', 'min:0'],
            'wrong_item' => ['boolean'],
            'return_awb' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $shipment = Shipment::query()->findOrFail($data['shipment_id']);

        if ($this->brands->isRestricted($user) && ! $this->brands->canUse($user, $shipment->brand_id)) {
            abort(403);
        }

        $into = null;

        if (! empty($data['into_store_id'])) {
            $into = Warehouse::query()->findOrFail((int) $data['into_store_id']);
            $this->facilities->assertCanWorkIn($user, $into);
        } else {
            $this->facilities->assertCanWorkIn($user, $shipment->warehouse);
        }

        $counts = [];

        foreach ($data['lines'] as $line) {
            $counts[(int) $line['item_id']] = ['good' => $line['good'] ?? 0, 'damaged' => $line['damaged'] ?? 0];
        }

        try {
            $return = $this->returns->receive(
                $shipment,
                ReturnKind::from($data['kind']),
                $counts,
                $user,
                $into,
                $data['notes'] ?? null,
                (bool) ($data['wrong_item'] ?? false),
                $data['return_awb'] ?? null,
            );
        } catch (OnlineOrderException $e) {
            return back()->withInput()->withToast('error', $e->getMessage());
        }

        $claim = $return->claim_status === ClaimStatus::Open
            ? ' A claim is open'.($return->claim_deadline_at ? ' until '.$return->claim_deadline_at->timezone(Cutoff::timezone())->format('j M, g:i A') : '').'.'
            : '';

        return redirect()->route('online-orders.returns.create')
            ->withToast('success', "{$return->number}: {$shipment->reference()} received back.{$claim}");
    }

    public function claim(Request $request, ShipmentReturn $return): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->can('marketplace.manage') || $user->can('marketplace.return'), 403);
        abort_unless($this->visible($user)->whereKey($return->id)->exists(), 403);

        $data = $request->validate([
            'claim_status' => ['required', Rule::enum(ClaimStatus::class)],
            'claim_reference' => ['nullable', 'string', 'max:64'],
            'claim_amount' => ['nullable', 'numeric', 'min:0'],
            'claim_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->returns->updateClaim(
            $return,
            ClaimStatus::from($data['claim_status']),
            $data['claim_reference'] ?? null,
            isset($data['claim_amount']) ? (string) $data['claim_amount'] : null,
            $data['claim_note'] ?? null,
        );

        return back()->withToast('success', "{$return->number}: claim updated.");
    }

    /**
     * @return Builder<ShipmentReturn>
     */
    private function visible(User $user): Builder
    {
        $query = ShipmentReturn::query();

        if ($this->brands->isRestricted($user)) {
            $query->whereIn('brand_id', $this->brands->brandIds($user) ?? []);
        }

        $facilityIds = $this->facilities->facilityIds($user);

        return $facilityIds === null ? $query : $query->whereIn('facility_id', $facilityIds);
    }

    private function authorizeSeeing(User $user): void
    {
        abort_unless($user->can('marketplace.view') || $user->can('marketplace.return'), 403);
        abort_if($this->brands->isRestricted($user), 403);
    }

    /**
     * Finished goods stores this person can put good returns back into.
     *
     * @return list<array{value: string, label: string}>
     */
    private function goodStores(User $user): array
    {
        return $this->facilities->scopeStores($user, Warehouse::query())
            ->where('type', WarehouseType::FinishedGoods->value)
            ->where('is_active', true)
            ->where('is_system', false)
            ->with('facility:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Warehouse $w) => ['value' => (string) $w->id, 'label' => "{$w->facility?->name} · {$w->name}"])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ShipmentReturn $r): array
    {
        return [
            'id' => $r->id,
            'number' => $r->number,
            'kind' => $r->kind->value,
            'kind_label' => $r->kind->label(),
            'awb' => $r->shipment?->awb,
            'order_number' => $r->shipment?->order_number,
            'courier' => $r->shipment?->courier,
            'batch_id' => $r->shipment?->label_batch_id,
            'return_awb' => $r->return_awb,
            'marketplace' => $r->relationLoaded('marketplace') ? $r->marketplace?->name : null,
            'brand' => $r->relationLoaded('brand') ? $r->brand?->name : null,
            'facility' => $r->relationLoaded('facility') ? $r->facility?->name : null,
            'received_by' => $r->relationLoaded('receiver') ? $r->receiver?->name : null,
            'received_at' => $r->received_at->toIso8601String(),
            'notes' => $r->notes,
            'wrong_item' => $r->wrong_item,
            'claim_status' => $r->claim_status->value,
            'claim_label' => $r->claim_status->label(),
            'claim_tone' => $r->claim_status->tone(),
            'claim_deadline_at' => $r->claim_deadline_at?->toIso8601String(),
            'claim_overdue' => $r->claimOverdue(),
            'claim_reference' => $r->claim_reference,
            'claim_amount' => $r->claim_amount,
            'claim_note' => $r->claim_note,
            'lines' => $r->lines->map(fn (ShipmentReturnLine $l) => [
                'item_id' => $l->item_id,
                'item' => $l->item?->name,
                'item_code' => $l->item?->code,
                'sent' => Decimal::strip($l->sent),
                'good' => Decimal::strip($l->good),
                'damaged' => Decimal::strip($l->damaged),
                'missing' => Decimal::strip($l->missing),
                'good_store' => $l->relationLoaded('goodStore') ? $l->goodStore?->name : null,
                'damaged_store' => $l->relationLoaded('damagedStore') ? $l->damagedStore?->name : null,
            ])->all(),
        ];
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
