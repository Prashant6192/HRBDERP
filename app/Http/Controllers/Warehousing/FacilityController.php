<?php

declare(strict_types=1);

namespace App\Http\Controllers\Warehousing;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Inventory\Models\StockBalance;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockAlertService;
use App\Domain\Inventory\Services\StockBalanceService;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Warehousing\Enums\FacilityCapability;
use App\Domain\Warehousing\Exceptions\FacilityException;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\FacilityType;
use App\Domain\Warehousing\Models\StoreCategory;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Domain\Warehousing\Services\FacilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Warehousing\StoreFacilityRequest;
use App\Http\Requests\Warehousing\UpdateFacilityRequest;
use App\Models\User;
use App\Support\Tables\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Facilities & Warehouses: the sites, what each may do, the stores inside
 * them and the people who work there.
 */
class FacilityController extends Controller
{
    private const array SORTABLE = ['code', 'name', 'city', 'is_active', 'created_at'];

    private const array TABS = ['overview', 'stores', 'inventory', 'employees', 'transfers', 'incoming', 'dispatch', 'production', 'activity', 'settings'];

    public function __construct(
        private readonly FacilityService $facilities,
        private readonly FacilityAccess $access,
        private readonly StockBalanceService $balances,
        private readonly StockAlertService $alerts,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Facility::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['type', 'city', 'capability', 'status']);

        $query = Facility::query()
            ->with(['type:id,code,name', 'manager:id,name', 'stores' => fn ($q) => $q->with('category:id,badge,name')])
            ->withCount(['stores', 'activeAssignments as employees_count'])
            ->search($table->search);

        if ($type = $table->filter('type')) {
            $query->where('facility_type_id', (int) $type);
        }

        if ($city = $table->filter('city')) {
            $query->where('city', $city);
        }

        if (($capability = FacilityCapability::tryFrom((string) $table->filter('capability'))) !== null) {
            $query->withCapability($capability);
        }

        if ($status = $table->filter('status')) {
            $query->where('is_active', $status === 'active');
        }

        $facilities = $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'code'));
        $stockValues = $this->stockValues($facilities->getCollection()->pluck('id')->all());

        $facilities->setCollection($facilities->getCollection()->map(fn (Facility $f) => [
            'id' => $f->id,
            'code' => $f->code,
            'name' => $f->name,
            'type' => $f->type?->name,
            'type_code' => $f->type?->code,
            'city' => $f->city,
            'state' => $f->state,
            'manager' => $f->manager?->name,
            'stores' => $f->stores->map(fn (Warehouse $w) => ['id' => $w->id, 'badge' => $w->badge(), 'name' => $w->name, 'is_active' => $w->is_active])->values()->all(),
            'stores_count' => $f->stores_count,
            'employees_count' => $f->employees_count,
            'stock_value' => $stockValues[$f->id] ?? '0',
            'capabilities' => array_map(fn (FacilityCapability $c) => ['key' => $c->value, 'badge' => $c->badge(), 'label' => $c->label()], $f->capabilities()),
            'can_manufacture' => $f->can_manufacture,
            'is_active' => $f->is_active,
        ]));

        return Inertia::render('facilities/index', [
            'facilities' => $facilities,
            'table' => $table->toArray(),
            'types' => FacilityType::query()->ordered()->get(['id', 'name'])->map(fn (FacilityType $t) => ['value' => $t->id, 'label' => $t->name])->all(),
            'cities' => Facility::query()->whereNotNull('city')->distinct()->orderBy('city')->pluck('city')->map(fn ($c) => ['value' => $c, 'label' => $c])->all(),
            'capabilities' => array_map(fn (FacilityCapability $c) => ['value' => $c->value, 'label' => $c->label()], FacilityCapability::cases()),
            'can' => ['create' => $request->user()->can('create', Facility::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Facility::class);

        return Inertia::render('facilities/create', [
            ...$this->formOptions(),
            'employees' => User::query()->active()->orderBy('name')->get(['id', 'name', 'designation', 'employee_code'])
                ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name, 'description' => trim(($u->employee_code ? "{$u->employee_code} · " : '').($u->designation ?? ''), ' ·')])->all(),
        ]);
    }

    public function store(StoreFacilityRequest $request): RedirectResponse
    {
        $this->authorize('create', Facility::class);

        $data = $request->validated();

        try {
            $facility = $this->facilities->create($data, $data['stores'] ?? [], $data['employees'] ?? [], $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        if (($data['opening_stock'] ?? 'later') === 'now' && $request->user()->can('bookOpeningStock', $facility)) {
            return redirect()->route('facilities.opening-stock.create', $facility)
                ->withToast('success', "{$facility->name} is set up. Book its opening stock to go live.");
        }

        return redirect()->route('facilities.show', $facility)->withToast('success', "{$facility->name} created with ".$facility->stores()->count().' store(s).');
    }

    public function show(Request $request, Facility $facility): Response
    {
        $this->authorize('view', $facility);

        $tab = in_array($request->query('tab'), self::TABS, strict: true) ? $request->query('tab') : 'overview';

        if ($tab === 'production' && ! $facility->can_manufacture) {
            $tab = 'overview';
        }

        $facility->load(['type:id,code,name', 'manager:id,name', 'stores' => fn ($q) => $q->with(['category:id,badge,name,kind,color', 'manager:id,name'])->withCount('locations')]);
        $storeIds = $facility->stores->pluck('id')->all();
        $user = $request->user();

        return Inertia::render('facilities/show', [
            'facility' => [
                ...$facility->only(['id', 'code', 'name', 'address_line_1', 'address_line_2', 'city', 'state', 'pincode', 'country', 'phone', 'email', 'gstin', 'notes', 'opening_stock_enabled', 'is_active', 'can_manufacture']),
                'type' => $facility->type?->name,
                'manager' => $facility->manager?->name,
                'manager_id' => $facility->manager_id,
                'capabilities' => array_map(fn (FacilityCapability $c) => ['key' => $c->value, 'label' => $c->label(), 'badge' => $c->badge(), 'enabled' => $facility->can($c)], FacilityCapability::cases()),
                'created_at' => $facility->created_at?->toIso8601String(),
            ],
            'tab' => $tab,
            'tabs' => array_values(array_filter(self::TABS, fn (string $t) => $t !== 'production' || $facility->can_manufacture)),
            'summary' => [
                'stores' => $facility->stores->count(),
                'active_stores' => $facility->stores->where('is_active', true)->count(),
                'employees' => $facility->activeAssignments()->distinct('user_id')->count('user_id'),
                'stock_value' => $this->stockValues([$facility->id])[$facility->id] ?? '0',
                'items_in_stock' => StockBalance::query()->whereIn('warehouse_id', $storeIds)->where('on_hand', '>', 0)->distinct('item_id')->count('item_id'),
                'open_transfers' => StockTransfer::query()->forFacility($facility->id)->open()->count(),
                'open_orders' => $facility->can_manufacture ? ManufacturingOrder::query()->where('facility_id', $facility->id)->open()->count() : 0,
            ],
            'stores' => $facility->stores->map(fn (Warehouse $w) => [
                'id' => $w->id, 'code' => $w->code, 'name' => $w->name, 'badge' => $w->badge(), 'category' => $w->category?->name,
                'type' => $w->type->value, 'is_quarantine' => $w->is_quarantine, 'is_active' => $w->is_active,
                'manager' => $w->manager?->name, 'locations_count' => $w->locations_count,
                'items' => StockBalance::query()->where('warehouse_id', $w->id)->where('on_hand', '>', 0)->distinct('item_id')->count('item_id'),
                'can_deactivate' => $user->can('deactivate', $w),
            ])->values()->all(),
            'inventory' => fn () => $tab === 'inventory' ? $this->inventory($facility) : null,
            'employees' => fn () => $tab === 'employees' ? $this->employees($facility) : null,
            'transfers' => fn () => in_array($tab, ['transfers', 'incoming', 'dispatch'], strict: true) ? $this->transfers($facility, $tab) : null,
            'incomingReceipts' => fn () => $tab === 'incoming' ? $this->incomingReceipts($storeIds) : null,
            'production' => fn () => $tab === 'production' ? $this->production($facility) : null,
            'activity' => fn () => $tab === 'activity' ? $this->activity($facility, $storeIds) : null,
            'options' => fn () => in_array($tab, ['stores', 'employees'], strict: true) ? [
                'categories' => $this->categoryOptions(),
                'employees' => User::query()->active()->orderBy('name')->get(['id', 'name', 'designation', 'employee_code'])
                    ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name, 'description' => $u->designation])->all(),
                'managers' => $this->managerOptions(),
            ] : null,
            'can' => [
                'update' => $user->can('update', $facility),
                'deactivate' => $user->can('deactivate', $facility),
                'add_store' => $user->can('create', Warehouse::class),
                'assign' => $user->can('assignEmployees', $facility),
                'opening_stock' => $user->can('bookOpeningStock', $facility) && $facility->opening_stock_enabled && $facility->is_active && $this->access->canWorkAt($user, $facility),
                'transfer' => $user->can('create', StockTransfer::class),
                'plan' => $facility->can_manufacture && $user->can('create', ProductionPlan::class),
                'view_stock' => $user->can('inventory.view'),
            ],
        ]);
    }

    public function edit(Request $request, Facility $facility): Response
    {
        $this->authorize('update', $facility);

        $facility->load('type:id,code,name');

        return Inertia::render('facilities/edit', [
            'facility' => $facility,
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateFacilityRequest $request, Facility $facility): RedirectResponse
    {
        $this->authorize('update', $facility);

        try {
            $facility = $this->facilities->update($facility, $request->validated(), $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('facilities.show', $facility)->withToast('success', "{$facility->name} updated.");
    }

    public function deactivate(Request $request, Facility $facility): RedirectResponse
    {
        $this->authorize('deactivate', $facility);

        try {
            $this->facilities->deactivate($facility, $request->user()->id);
        } catch (FacilityException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$facility->name} deactivated. Its history is kept.");
    }

    public function activate(Request $request, Facility $facility): RedirectResponse
    {
        $this->authorize('deactivate', $facility);

        $this->facilities->activate($facility, $request->user()->id);

        return back()->withToast('success', "{$facility->name} is active again.");
    }

    public function openingStock(Request $request, Facility $facility): RedirectResponse
    {
        $this->authorize('update', $facility);

        $enabled = filter_var($request->input('enabled'), FILTER_VALIDATE_BOOL);
        $this->facilities->setOpeningStock($facility, $enabled, $request->user()->id);

        return back()->withToast('success', $enabled
            ? "Opening stock entry is open again for {$facility->name}."
            : "Opening stock entry is closed for {$facility->name}. Stock now moves only through receipts, production and transfers.");
    }

    // ---- Tab data --------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function inventory(Facility $facility): array
    {
        $rows = [];

        foreach ($facility->stores->where('is_active', true) as $store) {
            $store->load('itemLevels');

            foreach ($this->balances->summaryForWarehouse($store) as $row) {
                $level = $this->alerts->levelAt($row['item'], $store, $row['on_hand']);
                $rows[] = [
                    'store_id' => $store->id,
                    'store' => $store->code,
                    'store_badge' => $store->badge(),
                    'item_id' => $row['item']->id,
                    'code' => $row['item']->code,
                    'name' => $row['item']->name,
                    'type' => $row['item']->type->value,
                    'uom' => $row['item']->stockUom?->code,
                    'display_scale' => $row['item']->stockUom?->display_scale ?? 3,
                    'on_hand' => (string) $row['on_hand'],
                    'reserved' => (string) $row['reserved'],
                    'available' => (string) $row['available'],
                    'level' => $level->value,
                    'level_label' => $level->shortLabel(),
                    'severity' => $level->severity(),
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$b['severity'], $a['store'], $a['name']] <=> [$a['severity'], $b['store'], $b['name']]);

        return ['rows' => $rows];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function employees(Facility $facility): array
    {
        return $facility->assignments()
            ->active()
            ->with(['user:id,name,email,designation,employee_code,status', 'user.roles:id,name', 'store:id,code,name', 'assigner:id,name'])
            ->orderByDesc('is_primary')->orderBy('id')
            ->get()
            ->map(fn (EmployeeAssignment $a) => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'name' => $a->user?->name,
                'email' => $a->user?->email,
                'employee_code' => $a->user?->employee_code,
                'roles' => $a->user?->roles->pluck('name')->values()->all() ?? [],
                'designation' => $a->designation ?? $a->user?->designation,
                'store_id' => $a->store_id,
                'store' => $a->store?->name,
                'is_primary' => $a->is_primary,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'assigned_by' => $a->assigner?->name,
            ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transfers(Facility $facility, string $tab): array
    {
        $query = StockTransfer::query()->with(['sourceFacility:id,name', 'destinationFacility:id,name', 'sourceStore:id,code', 'destinationStore:id,code'])->withCount('lines');

        match ($tab) {
            'incoming' => $query->where('destination_facility_id', $facility->id)->open(),
            'dispatch' => $query->where('source_facility_id', $facility->id),
            default => $query->forFacility($facility->id),
        };

        return $query->orderByDesc('id')->limit(50)->get()->map(fn (StockTransfer $t) => [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status->value,
            'status_label' => $t->status->label(),
            'tone' => $t->status->tone(),
            'direction' => $t->source_facility_id === $facility->id ? 'out' : 'in',
            'from' => $t->sourceFacility->name.' / '.$t->sourceStore->code,
            'to' => $t->destinationFacility->name.' / '.$t->destinationStore->code,
            'lines_count' => $t->lines_count,
            'expected_at' => $t->expected_at?->toDateString(),
            'created_at' => $t->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<array<string, mixed>>
     */
    private function incomingReceipts(array $storeIds): array
    {
        return GoodsReceipt::query()
            ->with(['vendor:id,name', 'warehouse:id,code'])
            ->whereIn('warehouse_id', $storeIds)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (GoodsReceipt $r) => [
                'id' => $r->id,
                'number' => $r->number,
                'vendor' => $r->vendor?->name,
                'store' => $r->warehouse?->code,
                'status' => $r->status->value,
                'status_label' => ucfirst($r->status->value),
                'received_at' => $r->received_at?->toDateString(),
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function production(Facility $facility): array
    {
        return [
            'plans' => ProductionPlan::query()->where('facility_id', $facility->id)->with(['formula:id,name', 'plannedUom:id,code'])
                ->orderByDesc('id')->limit(15)->get()
                ->map(fn (ProductionPlan $p) => [
                    'id' => $p->id, 'number' => $p->number, 'formula' => $p->formula?->name, 'quantity' => $p->planned_quantity, 'uom' => $p->plannedUom?->code,
                    'status' => $p->status->value, 'status_label' => $p->status->label(), 'planned_start_date' => $p->planned_start_date?->toDateString(),
                ])->all(),
            'orders' => ManufacturingOrder::query()->where('facility_id', $facility->id)->with(['product:id,name', 'plannedUom:id,code'])
                ->orderByDesc('id')->limit(15)->get()
                ->map(fn (ManufacturingOrder $o) => [
                    'id' => $o->id, 'number' => $o->number, 'product' => $o->product?->name, 'quantity' => $o->planned_quantity, 'uom' => $o->plannedUom?->code,
                    'status' => $o->status->value, 'status_label' => $o->status->label(), 'started_at' => $o->started_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /**
     * @param  list<int>  $storeIds
     * @return list<array<string, mixed>>
     */
    private function activity(Facility $facility, array $storeIds): array
    {
        return AuditLog::query()
            ->where(function (Builder $q) use ($facility, $storeIds): void {
                $q->where(fn (Builder $q) => $q->where('auditable_type', Facility::class)->where('auditable_id', $facility->id))
                    ->orWhere(fn (Builder $q) => $q->where('auditable_type', Warehouse::class)->whereIn('auditable_id', $storeIds))
                    ->orWhere(fn (Builder $q) => $q->where('auditable_type', EmployeeAssignment::class)->whereIn('auditable_id', $facility->assignments()->pluck('id')))
                    ->orWhere(fn (Builder $q) => $q->where('auditable_type', StockTransfer::class)->whereIn('auditable_id', StockTransfer::query()->forFacility($facility->id)->pluck('id')));
            })
            ->latest('created_at')
            ->limit(40)
            ->get(['id', 'user_name', 'action', 'description', 'auditable_type', 'auditable_id', 'auditable_label', 'created_at'])
            ->map(fn (AuditLog $e) => [
                'id' => $e->id,
                'actor' => $e->user_name ?? 'System',
                'action' => $e->action->label(),
                'subject' => $e->auditable_label,
                'description' => $e->description,
                'created_at' => $e->created_at?->toIso8601String(),
            ])->all();
    }

    // ---- Options -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'types' => FacilityType::query()->active()->ordered()->get()
                ->map(fn (FacilityType $t) => ['value' => $t->id, 'label' => $t->name, 'code' => $t->code, 'description' => $t->description, 'defaults' => $t->default_capabilities ?? []])->all(),
            'categories' => $this->categoryOptions(),
            'capabilities' => array_map(fn (FacilityCapability $c) => ['key' => $c->value, 'label' => $c->label(), 'badge' => $c->badge(), 'description' => $c->description()], FacilityCapability::cases()),
            'managers' => $this->managerOptions(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryOptions(): array
    {
        return StoreCategory::query()->selectable()->ordered()->get()
            ->map(fn (StoreCategory $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'badge' => $c->badge, 'kind' => $c->kind->value, 'icon' => $c->icon, 'color' => $c->color, 'description' => $c->description])->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function managerOptions(): array
    {
        return User::query()->active()->orderBy('name')->get(['id', 'name'])
            ->map(fn (User $u) => ['value' => $u->id, 'label' => $u->name])->all();
    }

    /**
     * Stock value per facility: on hand × the lot's cost, or the item's
     * standard cost when the lot has none.
     *
     * @param  list<int>  $facilityIds
     * @return array<int, string>
     */
    private function stockValues(array $facilityIds): array
    {
        if ($facilityIds === []) {
            return [];
        }

        return DB::table('stock_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->leftJoin('inventory_lots as l', 'l.id', '=', 'b.lot_id')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->whereIn('w.facility_id', $facilityIds)
            ->where('b.on_hand', '>', 0)
            ->groupBy('w.facility_id')
            ->selectRaw('w.facility_id, SUM(b.on_hand * COALESCE(l.unit_cost, i.standard_cost, 0)) AS value')
            ->pluck('value', 'facility_id')
            ->map(fn ($v) => number_format((float) $v, 2, '.', ''))
            ->all();
    }
}
