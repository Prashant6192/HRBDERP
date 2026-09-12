<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehousing;

use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\StoreService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehousing\StoreWarehouseRequest;
use App\Http\Requests\Warehousing\UpdateWarehouseRequest;
use App\Models\User;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Warehouse master data.
 *
 * The controller stays thin: it authorises, validates through a form request,
 * and hands the work to the model. Anything that needs a decision made about
 * it belongs in the domain, not here.
 */
class WarehouseController extends Controller
{
    /**
     * Columns a user is allowed to sort by. Anything else in the query string
     * is ignored rather than passed to the database.
     *
     * @var list<string>
     */
    private const SORTABLE = ['code', 'name', 'type', 'city', 'facility_id', 'is_active', 'created_at'];

    public function __construct(private readonly StoreService $stores) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Warehouse::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['type', 'status', 'facility']);

        $query = Warehouse::query()
            ->with(['manager:id,name', 'facility:id,code,name', 'category:id,badge,name'])
            ->where('is_system', false)
            ->withCount('locations')
            ->search($table->search);

        if ($facility = $table->filter('facility')) {
            $query->where('facility_id', (int) $facility);
        }

        if ($type = $table->filter('type')) {
            $query->where('type', $type);
        }

        if ($status = $table->filter('status')) {
            $query->where('is_active', $status === 'active');
        }

        return Inertia::render('warehouses/index', [
            'warehouses' => $table->paginate(
                $table->applySorting($query, self::SORTABLE, fallback: 'code')
            ),
            'table' => $table->toArray(),
            'types' => $this->typeOptions(),
            'facilities' => $this->facilityOptions(),
            'can' => [
                'create' => $request->user()->can('create', Warehouse::class),
                'export' => $request->user()->can('export', Warehouse::class),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Warehouse::class);

        return Inertia::render('warehouses/create', [
            'types' => $this->typeOptions(),
            'managers' => $this->managerOptions(),
            'facilities' => $this->facilityOptions(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(StoreWarehouseRequest $request): RedirectResponse
    {
        $this->authorize('create', Warehouse::class);

        $warehouse = Warehouse::create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Warehouse {$warehouse->code} created.",
        ]);

        return to_route('warehouses.index');
    }

    public function show(Request $request, Warehouse $warehouse): Response
    {
        $this->authorize('view', $warehouse);

        $warehouse->load(['manager:id,name', 'facility:id,code,name', 'category:id,badge,name', 'locations' => fn ($query) => $query->orderBy('code')]);

        return Inertia::render('warehouses/show', [
            'warehouse' => $warehouse,
            'hasHistory' => $warehouse->hasOperationalHistory(),
            'can' => [
                'update' => $request->user()->can('update', $warehouse),
                'delete' => $request->user()->can('delete', $warehouse),
            ],
        ]);
    }

    public function edit(Request $request, Warehouse $warehouse): Response
    {
        $this->authorize('update', $warehouse);

        return Inertia::render('warehouses/edit', [
            'warehouse' => $warehouse,
            'types' => $this->typeOptions(),
            'managers' => $this->managerOptions(),
            'facilities' => $this->facilityOptions(),
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('update', $warehouse);

        $warehouse->update([
            ...$request->validated(),
            'updated_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Warehouse {$warehouse->code} updated.",
        ]);

        return to_route('warehouses.show', $warehouse);
    }

    /**
     * Soft-deletes a warehouse nobody ever used. One with stock history is
     * refused — it is deactivated instead, so the ledger keeps its store.
     */
    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('delete', $warehouse);

        try {
            $this->stores->delete($warehouse);
        } catch (FacilityException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Warehouse {$warehouse->code} removed.",
        ]);

        return to_route('warehouses.index');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return array_map(
            static fn (WarehouseType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            WarehouseType::cases(),
        );
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function facilityOptions(): array
    {
        return Facility::query()->ordered()->get(['id', 'code', 'name'])
            ->map(static fn (Facility $f): array => ['value' => $f->id, 'label' => "{$f->name} ({$f->code})"])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string, kind: string}>
     */
    private function categoryOptions(): array
    {
        return StoreCategory::query()->selectable()->ordered()->get(['id', 'name', 'badge', 'kind'])
            ->map(static fn (StoreCategory $c): array => ['value' => $c->id, 'label' => "{$c->name} ({$c->badge})", 'kind' => $c->kind->value])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function managerOptions(): array
    {
        return User::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (User $user): array => [
                'value' => $user->id,
                'label' => $user->name,
            ])
            ->all();
    }
}
