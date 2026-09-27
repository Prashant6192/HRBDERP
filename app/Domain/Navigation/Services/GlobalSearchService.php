<?php

declare(strict_types=1);

namespace App\Domain\Navigation\Services;

use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Marketplace\Models\Shipment;
use App\Domain\Marketplace\Services\BrandAccess;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Models\Vendor;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ctrl K: find a record by what people call it — a material's code or
 * name, a batch number, an order, transfer or dispatch number, an AWB, a
 * vendor, a colleague.
 *
 * Each kind of record is searched only for someone who may open it, and
 * every result links to the page that already shows it. Pages themselves
 * are found in the browser, from the navigation the person can see.
 */
class GlobalSearchService
{
    private const int PER_GROUP = 5;

    public function __construct(private readonly BrandAccess $brands) {}

    /**
     * @return list<array{group: string, items: list<array{title: string, subtitle: string, href: string, badge: string|null}>}>
     */
    public function search(User $user, string $term): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2 || $this->brands->isRestricted($user)) {
            return [];
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
        $groups = [];

        $this->group($groups, 'Materials and products', $this->items($user, $like));
        $this->group($groups, 'Batches', $this->lots($user, $like));
        $this->group($groups, 'Manufacturing orders', $this->orders($user, $like));
        $this->group($groups, 'Stock transfers', $this->transfers($user, $like));
        $this->group($groups, 'Dispatches', $this->dispatches($user, $like));
        $this->group($groups, 'Online orders', $this->parcels($user, $like));
        $this->group($groups, 'Production plans', $this->plans($user, $like));
        $this->group($groups, 'Vendors', $this->vendors($user, $like));
        $this->group($groups, 'People', $this->people($user, $like));

        return $groups;
    }

    /**
     * @return Collection<int, array{title: string, subtitle: string, href: string, badge: string|null}>
     */
    private function items(User $user, string $like): Collection
    {
        $routes = [
            ItemType::RawMaterial->value => 'raw-materials.show',
            ItemType::Consumable->value => 'raw-materials.show',
            ItemType::PackagingMaterial->value => 'packaging-materials.show',
            ItemType::FinishedGood->value => 'products.show',
            ItemType::SemiFinished->value => 'products.show',
        ];

        $types = array_values(array_filter(array_keys($routes), fn (string $t) => $user->can(ItemType::from($t)->permissionModule().'.view')));

        if ($types === []) {
            return collect();
        }

        return Item::query()->whereIn('type', $types)
            ->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like))
            ->with('stockUom:id,code')
            ->orderByRaw('case when code ilike ? then 0 else 1 end', [str_replace('%', '', $like)])
            ->orderBy('name')->limit(self::PER_GROUP)
            ->get(['id', 'code', 'name', 'type', 'is_active', 'stock_uom_id'])
            ->map(fn (Item $i) => [
                'title' => "{$i->code} · {$i->name}",
                'subtitle' => $i->type->label().($i->stockUom ? ' · '.$i->stockUom->code : ''),
                'href' => route($routes[$i->type->value], $i->id),
                'badge' => $i->is_active ? null : 'Inactive',
            ]);
    }

    private function lots(User $user, string $like): Collection
    {
        if (! $user->can('inventory.view')) {
            return collect();
        }

        return InventoryLot::query()->where('batch_number', 'ilike', $like)
            ->with('item:id,code,name')->latest('id')->limit(self::PER_GROUP)
            ->get(['id', 'batch_number', 'item_id', 'qc_status', 'expiry_at'])
            ->map(fn (InventoryLot $l) => [
                'title' => "{$l->batch_number} · {$l->item?->name}",
                'subtitle' => $l->qc_status->label().($l->expiry_at ? ' · expires '.$l->expiry_at->format('M Y') : ''),
                'href' => route('lots.show', $l->id),
                'badge' => null,
            ]);
    }

    private function orders(User $user, string $like): Collection
    {
        if (! $user->can('production.view')) {
            return collect();
        }

        return ManufacturingOrder::query()->where('number', 'ilike', $like)
            ->with('product:id,name')->latest('id')->limit(self::PER_GROUP)
            ->get(['id', 'number', 'product_id', 'status'])
            ->map(fn (ManufacturingOrder $o) => [
                'title' => "{$o->number} · {$o->product?->name}",
                'subtitle' => $o->status->label(),
                'href' => route('manufacturing.show', $o->id),
                'badge' => null,
            ]);
    }

    private function transfers(User $user, string $like): Collection
    {
        if (! $user->can('inventory.view')) {
            return collect();
        }

        return StockTransfer::query()->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('vehicle_ref', 'ilike', $like))
            ->with(['sourceFacility:id,name', 'destinationFacility:id,name'])->latest('id')->limit(self::PER_GROUP)
            ->get(['id', 'number', 'status', 'source_facility_id', 'destination_facility_id'])
            ->map(fn (StockTransfer $t) => [
                'title' => $t->number,
                'subtitle' => "{$t->sourceFacility?->name} → {$t->destinationFacility?->name} · {$t->status->label()}",
                'href' => route('transfers.show', $t->id),
                'badge' => null,
            ]);
    }

    private function dispatches(User $user, string $like): Collection
    {
        if (! $user->can('dispatch.view')) {
            return collect();
        }

        return Dispatch::query()->where(fn ($q) => $q->where('number', 'ilike', $like)->orWhere('invoice_number', 'ilike', $like))
            ->with('customer:id,name')->latest('id')->limit(self::PER_GROUP)
            ->get(['id', 'number', 'status', 'customer_id', 'invoice_number'])
            ->map(fn (Dispatch $d) => [
                'title' => $d->number.($d->invoice_number ? " · invoice {$d->invoice_number}" : ''),
                'subtitle' => "{$d->customer?->name} · {$d->status->label()}",
                'href' => route('dispatches.show', $d->id),
                'badge' => null,
            ]);
    }

    private function parcels(User $user, string $like): Collection
    {
        if (! $user->can('marketplace.view')) {
            return collect();
        }

        $query = Shipment::query()->where(fn ($q) => $q->where('awb', 'ilike', $like)->orWhere('order_number', 'ilike', $like)->orWhere('alt_code', 'ilike', $like));

        return $this->brands->scopeByBrand($user, $query)
            ->with(['batch:id,for_date', 'brand:id,name'])->latest('id')->limit(self::PER_GROUP)
            ->get(['id', 'awb', 'order_number', 'courier', 'status', 'label_batch_id', 'brand_id'])
            ->map(fn (Shipment $s) => [
                'title' => $s->reference(),
                'subtitle' => trim(($s->brand?->name ?? '').' · '.($s->courier ?? 'courier not read').' · '.$s->status->label(), ' ·'),
                'href' => route('online-orders.index', array_filter([
                    'date' => $s->batch?->for_date?->toDateString(),
                    'search' => $s->awb ?? $s->order_number,
                ])),
                'badge' => null,
            ]);
    }

    private function plans(User $user, string $like): Collection
    {
        if (! $user->can('planning.view')) {
            return collect();
        }

        return ProductionPlan::query()->where('number', 'ilike', $like)->latest('id')->limit(self::PER_GROUP)
            ->get(['id', 'number', 'status'])
            ->map(fn (ProductionPlan $p) => [
                'title' => $p->number,
                'subtitle' => $p->status->label(),
                'href' => route('plans.show', $p->id),
                'badge' => null,
            ]);
    }

    private function vendors(User $user, string $like): Collection
    {
        if (! $user->can('vendor.view')) {
            return collect();
        }

        return Vendor::query()->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like)->orWhere('gstin', 'ilike', $like))
            ->orderBy('name')->limit(self::PER_GROUP)
            ->get(['id', 'code', 'name', 'city'])
            ->map(fn (Vendor $v) => [
                'title' => "{$v->code} · {$v->name}",
                'subtitle' => $v->city ?? 'Vendor',
                'href' => route('vendors.show', $v->id),
                'badge' => null,
            ]);
    }

    private function people(User $user, string $like): Collection
    {
        if (! $user->can('user.view')) {
            return collect();
        }

        return User::query()->employees()
            ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('email', 'ilike', $like)->orWhere('employee_code', 'ilike', $like))
            ->orderBy('name')->limit(self::PER_GROUP)
            ->get(['id', 'name', 'email', 'designation', 'employee_code'])
            ->map(fn (User $u) => [
                'title' => $u->name,
                'subtitle' => trim(($u->designation ?? '').' · '.$u->email, ' ·'),
                'href' => route('users.show', $u->id),
                'badge' => null,
            ]);
    }

    /**
     * @param  list<array{group: string, items: list<array<string, mixed>>}>  $groups
     * @param  Collection<int, array{title: string, subtitle: string, href: string, badge: string|null}>  $items
     */
    private function group(array &$groups, string $label, Collection $items): void
    {
        if ($items->isNotEmpty()) {
            $groups[] = ['group' => $label, 'items' => $items->values()->all()];
        }
    }
}
