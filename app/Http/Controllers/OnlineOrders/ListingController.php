<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\ShipmentLine;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\Product;
use App\Domain\MasterData\Services\ProductPackService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dispatch → SKU mapping: what each marketplace prints for a product, and
 * which product that is. The labels waiting on a mapping come first.
 */
class ListingController extends Controller
{
    public function __construct(
        private readonly OnlineOrderService $orders,
        private readonly ProductPackService $packs,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('marketplace.manage');

        $waiting = ShipmentLine::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
            ->join('marketplaces', 'marketplaces.id', '=', 'shipments.marketplace_id')
            ->join('brands', 'brands.id', '=', 'shipments.brand_id')
            ->whereIn('shipments.status', ShipmentStatus::awaitingPacking())
            ->whereNull('shipment_lines.listing_id')
            ->groupBy('shipments.marketplace_id', 'marketplaces.name', 'shipments.brand_id', 'brands.name', 'shipment_lines.seller_sku')
            ->selectRaw('shipments.marketplace_id, marketplaces.name AS marketplace, shipments.brand_id, brands.name AS brand, shipment_lines.seller_sku, COUNT(DISTINCT shipments.id) AS parcels, MAX(shipment_lines.description) AS description')
            ->orderByDesc('parcels')
            ->get()
            ->map(fn ($r) => [
                'marketplace_id' => (int) $r->marketplace_id, 'marketplace' => $r->marketplace,
                'brand_id' => (int) $r->brand_id, 'brand' => $r->brand,
                'seller_sku' => $r->seller_sku, 'description' => $r->description, 'parcels' => (int) $r->parcels,
            ])
            ->all();

        $listings = MarketplaceListing::query()
            ->with(['marketplace:id,name', 'brand:id,name', 'item:id,code,name', 'components.item:id,code,name'])
            ->orderBy('marketplace_id')->orderBy('brand_id')->orderBy('seller_sku')
            ->get()
            ->map(fn (MarketplaceListing $l) => [
                'id' => $l->id, 'marketplace' => $l->marketplace?->name, 'marketplace_id' => $l->marketplace_id,
                'brand' => $l->brand?->name, 'brand_id' => $l->brand_id, 'seller_sku' => $l->seller_sku,
                'item_id' => $l->item_id, 'item' => $l->item?->name, 'item_code' => $l->item?->code,
                'units_per_order' => $l->units_per_order, 'is_active' => $l->is_active,
                'components' => $l->components->map(fn ($c) => [
                    'item_id' => $c->item_id, 'item' => $c->item?->name, 'item_code' => $c->item?->code, 'units' => $c->units_per_order,
                ])->all(),
            ])
            ->all();

        return Inertia::render('online-orders/listings', [
            'waiting' => $waiting,
            'listings' => $listings,
            'pack_moves' => $this->packMoves(),
            'products' => Item::query()->where('type', ItemType::FinishedGood->value)->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Item $i) => ['value' => (string) $i->id, 'label' => $i->name, 'hint' => $i->code])->all(),
            'marketplaces' => Marketplace::query()->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['value' => (string) $m->id, 'label' => $m->name])->all(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])->all(),
        ]);
    }

    /**
     * Listings sold as two of one product, now to be taken from that
     * product's Pack of 2 (added if it is not on file yet). Waiting parcels
     * are matched again at once.
     */
    public function usePacks(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.manage');

        $data = $request->validate([
            'item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('type', ItemType::FinishedGood->value)->whereNull('deleted_at')],
            'quantity' => ['required', 'integer', 'min:2', 'max:12'],
        ]);

        $single = Product::query()->findOrFail($data['item_id']);

        if ($single->pack_of_item_id !== null) {
            return back()->withToast('error', "{$single->name} is already a pack.");
        }

        $pack = $this->packs->packOf($single, (int) $data['quantity'], $request->user()->id);
        $moved = 0;

        try {
            foreach ($this->singlesSoldAsPacks($single->id, (int) $data['quantity']) as $listing) {
                $this->orders->mapSkuTo($listing->marketplace, $listing->brand, $listing->seller_sku, [['item' => $pack, 'units' => 1]], $request->user());
                $moved++;
            }
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$moved} listing(s) now take {$pack->name} ({$pack->code}). Scan its stock in as Pack of {$data['quantity']} on the Stock screen.");
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.manage');

        $data = $request->validate([
            'marketplace_id' => ['required', 'integer', Rule::exists('marketplaces', 'id')],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'seller_sku' => ['required', 'string', 'max:255'],
            ...$this->componentRules(),
        ]);

        try {
            $listing = $this->orders->mapSkuTo(
                Marketplace::query()->findOrFail($data['marketplace_id']),
                Brand::query()->findOrFail($data['brand_id']),
                $data['seller_sku'],
                $this->components($data),
                $request->user(),
            );
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "\"{$listing->seller_sku}\" is {$this->describe($listing)}. Waiting parcels have been matched.");
    }

    public function update(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $this->authorize('marketplace.manage');

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            ...$this->componentRules(required: $request->boolean('is_active')),
        ]);

        if (! $data['is_active']) {
            $listing->update(['is_active' => false]);

            return back()->withToast('success', "\"{$listing->seller_sku}\" will no longer be matched.");
        }

        try {
            $listing = $this->orders->mapSkuTo(
                $listing->marketplace,
                $listing->brand,
                $listing->seller_sku,
                $this->components($data),
                $request->user(),
            );
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "\"{$listing->seller_sku}\" is now {$this->describe($listing)}.");
    }

    /**
     * Products sold in listings as two (or more) of the one product, not
     * yet taken from a pack.
     *
     * @return list<array{item_id: int, item: string|null, item_code: string|null, quantity: int, pack: string|null, listings: list<array{id: int, marketplace: string|null, brand: string|null, seller_sku: string}>}>
     */
    private function packMoves(): array
    {
        return MarketplaceListing::query()
            ->where('is_active', true)
            ->where('units_per_order', '>', 1)
            ->whereHas('item', fn ($q) => $q->whereNull('pack_of_item_id'))
            ->has('components', '=', 1)
            ->with(['marketplace:id,name', 'brand:id,name', 'item:id,code,name', 'item.packs:id,code,name,pack_of_item_id,pack_quantity'])
            ->orderBy('item_id')
            ->get()
            ->groupBy(fn (MarketplaceListing $l) => $l->item_id.':'.$l->units_per_order)
            ->map(function ($group) {
                $first = $group->first();
                $pack = $first->item?->packs->firstWhere('pack_quantity', $first->units_per_order);

                return [
                    'item_id' => $first->item_id,
                    'item' => $first->item?->name,
                    'item_code' => $first->item?->code,
                    'quantity' => $first->units_per_order,
                    'pack' => $pack?->name,
                    'listings' => $group->map(fn (MarketplaceListing $l) => [
                        'id' => $l->id, 'marketplace' => $l->marketplace?->name, 'brand' => $l->brand?->name, 'seller_sku' => $l->seller_sku,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, MarketplaceListing>
     */
    private function singlesSoldAsPacks(int $itemId, int $quantity): Collection
    {
        return MarketplaceListing::query()
            ->where('is_active', true)
            ->where('item_id', $itemId)
            ->where('units_per_order', $quantity)
            ->has('components', '=', 1)
            ->with(['marketplace', 'brand'])
            ->get();
    }

    /**
     * One product with its pieces, or several for a combo. A single
     * item_id / units_per_order pair is still accepted.
     *
     * @return array<string, mixed>
     */
    private function componentRules(bool $required = true): array
    {
        return [
            'components' => [Rule::requiredIf(fn () => $required && ! request()->filled('item_id')), 'array', 'min:1', 'max:10'],
            'components.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')],
            'components.*.units' => ['required', 'integer', 'min:1', 'max:100'],
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'units_per_order' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{item: Item, units: int}>
     */
    private function components(array $data): array
    {
        $rows = $data['components'] ?? [['item_id' => $data['item_id'] ?? null, 'units' => $data['units_per_order'] ?? 1]];
        $items = Item::query()->whereIn('id', array_column($rows, 'item_id'))->get()->keyBy('id');

        return array_values(array_map(
            fn (array $row) => ['item' => $items->get((int) $row['item_id']) ?? throw new OnlineOrderException('Choose the product.'), 'units' => (int) $row['units']],
            $rows,
        ));
    }

    private function describe(MarketplaceListing $listing): string
    {
        return $listing->components
            ->map(fn ($c) => $c->item?->name.($c->units_per_order > 1 ? " × {$c->units_per_order}" : ''))
            ->implode(' + ');
    }
}
