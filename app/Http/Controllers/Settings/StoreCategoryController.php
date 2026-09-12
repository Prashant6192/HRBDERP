<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreCategoryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store category master: add, rename, re-badge, deactivate. Never
 * delete — a category in use is referenced by stores and their history.
 */
class StoreCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('setting.view');

        return Inertia::render('settings/store-categories', [
            'categories' => StoreCategory::query()->withCount(['stores' => fn ($q) => $q->withTrashed()])->ordered()->get()
                ->map(fn (StoreCategory $c) => [
                    'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'badge' => $c->badge, 'kind' => $c->kind->value,
                    'kind_label' => $c->kind->label(), 'icon' => $c->icon, 'color' => $c->color, 'description' => $c->description,
                    'is_system' => $c->is_system, 'is_active' => $c->is_active, 'sort_order' => $c->sort_order, 'stores_count' => $c->stores_count,
                ])->all(),
            'kinds' => array_map(fn (WarehouseType $t) => ['value' => $t->value, 'label' => $t->label(), 'badge' => $t->badge()], WarehouseType::cases()),
            'can' => ['edit' => $request->user()->can('setting.edit')],
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Gate::authorize('setting.edit');

        $category = StoreCategory::create([...$request->validated(), 'is_system' => false]);

        return back()->withToast('success', "Store category {$category->name} added.");
    }

    public function update(StoreCategoryRequest $request, StoreCategory $storeCategory): RedirectResponse
    {
        Gate::authorize('setting.edit');

        $data = $request->validated();

        // The behaviour behind a category is fixed once stores use it.
        if (isset($data['kind']) && $data['kind'] !== $storeCategory->kind->value && $storeCategory->isInUse()) {
            return back()->withErrors(['kind' => "{$storeCategory->name} is used by stores, so what it holds cannot change. Add a new category instead."]);
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $storeCategory->kind === WarehouseType::InTransit) {
            return back()->withErrors(['is_active' => 'The in-transit category is used by the system and stays active.']);
        }

        $storeCategory->update($data);

        return back()->withToast('success', "Store category {$storeCategory->name} updated.");
    }
}
