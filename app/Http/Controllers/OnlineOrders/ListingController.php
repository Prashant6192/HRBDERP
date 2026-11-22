<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Marketplace\Enums\ShipmentStatus;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Marketplace\Models\Marketplace;
use App\Domain\Marketplace\Models\MarketplaceListing;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Models\ShipmentLine;
use App\Domain\Marketplace\Services\OnlineOrderService;
use App\Domain\Marketplace\Support\PackCheck;
use App\Domain\Marketplace\Support\ProductPhotos;
use App\Domain\Marketplace\Support\SkuReading;
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
            ->get();

        // Every title the labels and invoices gave each waiting SKU: the
        // one that names a size reads best.
        $titles = ShipmentLine::query()
            ->join('shipments', 'shipments.id', '=', 'shipment_lines.shipment_id')
            ->whereIn('shipments.status', ShipmentStatus::awaitingPacking())
            ->whereNull('shipment_lines.listing_id')
            ->whereNotNull('shipment_lines.description')
            ->distinct()
            ->get(['shipments.marketplace_id', 'shipments.brand_id', 'shipment_lines.seller_sku', 'shipment_lines.description'])
            ->groupBy(fn ($r) => "{$r->marketplace_id}|{$r->brand_id}|{$r->seller_sku}");

        // The parcels each waiting SKU is on, so the label can be looked at
        // before the SKU is mapped: newest first, a handful each.
        $orders = Shipment::query()
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->whereHas('lines', fn ($q) => $q->whereNull('listing_id'))
            ->with(['lines' => fn ($q) => $q->whereNull('listing_id'), 'batch:id,for_date'])
            ->latest('id')
            ->limit(500)
            ->get()
            ->flatMap(fn (Shipment $s) => $s->lines->map(fn (ShipmentLine $l) => [
                'key' => "{$s->marketplace_id}|{$s->brand_id}|{$l->seller_sku}",
                ...self::order($s, $l),
            ]))
            ->groupBy('key');

        $products = Item::query()->where('type', ItemType::FinishedGood->value)->where('is_active', true)->orderBy('name')
            ->get(['id', 'code', 'name', 'pack_of_item_id', 'pack_quantity']);
        $candidates = $products->map(fn (Item $i) => [
            'id' => (int) $i->id, 'name' => (string) $i->name,
            'pack_of' => $i->pack_of_item_id === null ? null : (int) $i->pack_of_item_id,
            'pack_quantity' => $i->pack_quantity === null ? null : (int) $i->pack_quantity,
        ])->all();

        $waiting = $waiting->map(function ($r) use ($titles, $candidates, $orders): array {
            $readings = ($titles->get("{$r->marketplace_id}|{$r->brand_id}|{$r->seller_sku}") ?? collect([(object) ['description' => $r->description]]))
                ->map(fn ($t) => SkuReading::of((string) $r->seller_sku, $t->description, $r->brand))
                ->sortByDesc(fn (SkuReading $x) => [(int) ($x->size !== null), mb_strlen((string) $x->name)])
                ->values();
            $reading = $readings->first() ?? SkuReading::of((string) $r->seller_sku, null, $r->brand);
            $suggested = $reading->suggest($candidates);

            return [
                'marketplace_id' => (int) $r->marketplace_id, 'marketplace' => $r->marketplace,
                'brand_id' => (int) $r->brand_id, 'brand' => $r->brand,
                'seller_sku' => $r->seller_sku, 'description' => $r->description, 'parcels' => (int) $r->parcels,
                'reading' => ['name' => $reading->name, 'size' => $reading->size, 'pack' => $reading->pack],
                'orders' => ($orders->get("{$r->marketplace_id}|{$r->brand_id}|{$r->seller_sku}") ?? collect())
                    ->take(8)->map(fn (array $o) => collect($o)->except('key')->all())->values()->all(),
                'suggested' => $suggested === null ? null : ['item_id' => (string) $suggested['id'], 'pieces' => $suggested['pieces']],
            ];
        })->all();

        // What the labels call each mapped SKU, to tell a real pack ("Pack
        // of 2") from an order quantity typed in as pieces.
        $printed = ShipmentLine::query()
            ->whereNotNull('listing_id')
            ->whereNotNull('description')
            ->groupBy('listing_id')
            ->selectRaw('listing_id, MAX(description) AS description')
            ->pluck('description', 'listing_id');

        // The newest orders of each mapped SKU, packed or not, so a mapping
        // can be checked against its labels before it is changed.
        $mappedOrders = ShipmentLine::query()
            ->whereNotNull('listing_id')
            ->with(['shipment.batch:id,for_date'])
            ->latest('id')
            ->limit(3000)
            ->get()
            ->filter(fn (ShipmentLine $l) => $l->shipment !== null)
            ->groupBy('listing_id')
            ->map(fn ($lines) => $lines->take(8)->map(fn (ShipmentLine $l) => self::order($l->shipment, $l))->values()->all());

        $listings = MarketplaceListing::query()
            ->with(['marketplace:id,name', 'brand:id,name', 'item:id,code,name', 'components.item:id,code,name,pack_of_item_id'])
            ->orderBy('marketplace_id')->orderBy('brand_id')->orderBy('seller_sku')
            ->get()
            ->map(fn (MarketplaceListing $l) => [
                // More than one piece of a single product, on an SKU that
                // does not say it is a pack of that many: most likely the
                // quantity of one order was typed in as the pieces.
                'suspect' => $this->suspect($l, $printed->get($l->id)),
                'reads_as' => $this->readsAs($l, $printed->get($l->id), $candidates, $products),
                'orders' => $mappedOrders->get($l->id, []),
                'description' => $printed->get($l->id),
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
            'products' => $products->map(function (Item $i): array {
                [$name, $size] = PackCheck::nameAndSize((string) $i->name);

                return [
                    'value' => (string) $i->id, 'label' => $i->name, 'hint' => $i->code,
                    // For the photo tiles: singles only, a pack is chosen with "Pack of 2".
                    'name' => $name, 'size' => $size, 'is_pack' => $i->pack_of_item_id !== null,
                    'photo' => ProductPhotos::forName((string) $i->name),
                ];
            })->all(),
            'marketplaces' => Marketplace::query()->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['value' => (string) $m->id, 'label' => $m->name])->all(),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->name])->all(),
        ]);
    }

    /**
     * More than one piece of a single product for one unit of an SKU that
     * does not show itself a pack: most likely an order's quantity typed
     * in as pieces. An SKU says it is a pack by its name ("Pack of 2",
     * "-P2"), by its size ("400ML" of a 200 ml product is two), or by
     * someone having said so.
     */
    private function suspect(MarketplaceListing $l, ?string $printed): bool
    {
        $only = $l->components->count() === 1 ? $l->components->first() : null;
        $units = (int) ($only?->units_per_order ?? 1);

        if (! $l->is_active || $l->pack_confirmed || $only === null || $units <= 1 || $only->item?->pack_of_item_id !== null) {
            return false;
        }

        if (SkuReading::of((string) $l->seller_sku, $printed, $l->brand?->name)->pack === $units) {
            return false;
        }

        $skuSize = SkuReading::millilitres((string) $l->seller_sku);
        $oneSize = SkuReading::millilitres((string) $only->item?->name);

        return ! ($skuSize !== null && $oneSize !== null && abs($skuSize - $units * $oneSize) < 0.5);
    }

    /**
     * Not the company's product (a label uploaded by mistake): the parcels
     * still waiting with this SKU are cancelled, so it leaves "Waiting to be
     * mapped" and the day's counts. Packed parcels are not touched.
     */
    public function dismiss(Request $request): RedirectResponse
    {
        $this->authorize('marketplace.manage');

        $data = $request->validate([
            'marketplace_id' => ['required', 'integer', Rule::exists('marketplaces', 'id')],
            'brand_id' => ['required', 'integer', Rule::exists('brands', 'id')],
            'seller_sku' => ['required', 'string', 'max:255'],
        ]);

        $key = MarketplaceListing::keyFor($data['seller_sku']);
        $parcels = Shipment::query()
            ->where('marketplace_id', $data['marketplace_id'])
            ->where('brand_id', $data['brand_id'])
            ->whereIn('status', ShipmentStatus::awaitingPacking())
            ->with('lines')
            ->get()
            ->filter(fn (Shipment $s) => $s->lines->contains(fn (ShipmentLine $l) => MarketplaceListing::keyFor($l->seller_sku) === $key));

        try {
            foreach ($parcels as $parcel) {
                $this->orders->cancel($parcel, "Not our product: SKU {$data['seller_sku']} uploaded by mistake", $request->user());
            }
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "\"{$data['seller_sku']}\" removed: {$parcels->count()} parcel(s) cancelled as not our product.");
    }

    /**
     * The product an SKU's own words clearly name, when it is not the one it
     * is mapped to ("Rahat Rooh Medicated Oil 300ml (pack of 2)" mapped to
     * Ratna): most likely the wrong tile was picked. Null when the words
     * agree or say too little.
     *
     * @param  list<array{id: int, name: string, pack_of: int|null, pack_quantity: int|null}>  $candidates
     * @param  Collection<int, Item>  $products
     */
    private function readsAs(MarketplaceListing $l, ?string $printed, array $candidates, Collection $products): ?string
    {
        if (! $l->is_active || $l->components->count() !== 1) {
            return null;
        }

        $suggested = SkuReading::of((string) $l->seller_sku, $printed, $l->brand?->name)->suggest($candidates)
            // The SKU text alone, when the label's title is the noisier one.
            ?? SkuReading::of((string) $l->seller_sku, (string) $l->seller_sku, $l->brand?->name)->suggest($candidates);

        if ($suggested === null) {
            return null;
        }

        $single = fn (?int $id) => ($item = $products->firstWhere('id', $id)) === null ? $id : ($item->pack_of_item_id ?? $item->id);
        $mapped = $l->components->first()->item_id;

        return $single((int) $suggested['id']) === $single((int) $mapped)
            ? null
            : $products->firstWhere('id', $single((int) $suggested['id']))?->name;
    }

    /**
     * Someone has looked: one unit of this SKU really is that many pieces.
     */
    public function confirm(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $this->authorize('marketplace.manage');
        $listing->forceFill(['pack_confirmed' => true])->save();

        return back()->withToast('success', "\"{$listing->seller_sku}\" kept as it is. It will not be flagged again.");
    }

    /**
     * One order an SKU was on, for the mapping screen: its numbers, the
     * quantity ordered, its dates and where its label is.
     *
     * @return array{shipment_id: int, order_number: string|null, awb: string|null, quantity: int, order_date: string|null, invoice_date: string|null, for_date: string|null, file_url: string|null, page: int}
     */
    private static function order(Shipment $s, ShipmentLine $l): array
    {
        return [
            'shipment_id' => $s->id,
            'order_number' => $s->order_number,
            'awb' => $s->awb,
            'quantity' => (int) $l->quantity,
            'order_date' => $s->order_date?->toDateString(),
            'invoice_date' => $s->invoice_date?->toDateString(),
            'for_date' => $s->batch?->for_date?->toDateString(),
            'file_url' => $s->label_file_id === null ? null : route('online-orders.files.show', $s->label_file_id),
            'page' => $s->pages[0] ?? 1,
        ];
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
                (bool) ($data['pack_confirmed'] ?? false),
            );
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "\"{$listing->seller_sku}\" is {$this->describe($listing)}. Waiting parcels have been matched.");
    }

    /**
     * Take a mapping off the file: its waiting parcels go back to "Waiting
     * to be mapped".
     */
    public function destroy(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $this->authorize('marketplace.manage');
        $sku = $listing->seller_sku;

        try {
            $back = $this->orders->deleteSkuMapping($listing, $request->user());
        } catch (OnlineOrderException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "\"{$sku}\" is no longer mapped.".($back > 0 ? " {$back} waiting parcel(s) are back under Waiting to be mapped." : ''));
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
                (bool) ($data['pack_confirmed'] ?? false),
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
            // Chosen as a pack (Pack of 2, a bundle) on purpose, so not flagged.
            'pack_confirmed' => ['nullable', 'boolean'],
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
