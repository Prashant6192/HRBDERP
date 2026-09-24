<?php

declare(strict_types=1);

namespace App\Http\Controllers\OnlineOrders;

use App\Domain\Access\Enums\RoleName;
use App\Domain\Contract\Models\Client;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dispatch → Brands: where each brand's parcels leave from, whose stock
 * they take, and which agency accounts upload for it.
 */
class BrandController extends Controller
{
    public function index(): Response
    {
        $this->authorize('marketplace.manage');

        return Inertia::render('online-orders/brands', [
            'brands' => Brand::query()
                ->with(['defaultWarehouse:id,code,name,facility_id', 'defaultWarehouse.facility:id,name', 'client:id,name', 'users:id,name,email'])
                ->orderBy('name')
                ->get()
                ->map(fn (Brand $b) => [
                    'id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'legal_name' => $b->legal_name, 'gstin' => $b->gstin,
                    'client_id' => $b->client_id, 'client' => $b->client?->name,
                    'default_warehouse_id' => $b->default_warehouse_id,
                    'store' => $b->defaultWarehouse ? "{$b->defaultWarehouse->facility?->name} · {$b->defaultWarehouse->name}" : null,
                    'is_active' => $b->is_active,
                    'users' => $b->users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])->all(),
                ])->all(),
            'stores' => Warehouse::query()
                ->where('type', WarehouseType::FinishedGoods->value)
                ->where('is_active', true)
                ->where('is_system', false)
                ->whereHas('facility', fn ($q) => $q->where('can_dispatch', true))
                ->with('facility:id,name')
                ->orderBy('name')
                ->get()
                ->map(fn (Warehouse $w) => ['value' => (string) $w->id, 'label' => "{$w->facility?->name} · {$w->name}"])->all(),
            'clients' => Client::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Client $c) => ['value' => (string) $c->id, 'label' => $c->name])->all(),
            'agencies' => User::query()->role(RoleName::EcommerceAgency->value)->orderBy('name')->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name, 'hint' => $u->email])->all(),
            'own_brand' => (string) config('erp.company.brand'),
        ]);
    }

    public function update(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('marketplace.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'string', 'regex:/^\d{2}[A-Z0-9]{13}$/'],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'default_warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('type', WarehouseType::FinishedGoods->value)],
            'is_active' => ['required', 'boolean'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', Rule::exists('users', 'id')],
        ], ['gstin.regex' => 'A GSTIN is 15 characters: two digits for the state, then the PAN and three more.']);

        $brand->update([
            ...collect($data)->except('user_ids')->all(),
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'updated_by' => $request->user()->id,
        ]);

        // Only agency accounts are given brands; everyone else sees them all.
        $agencyIds = User::query()->role(RoleName::EcommerceAgency->value)->whereIn('id', $data['user_ids'] ?? [])->pluck('id')->all();
        $brand->users()->sync($agencyIds);

        return back()->withToast('success', "{$brand->name} saved.");
    }
}
