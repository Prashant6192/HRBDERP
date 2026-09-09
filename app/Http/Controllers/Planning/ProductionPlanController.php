<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Domain\Formulation\Models\Formula;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Models\ProductionPlanLine;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\StoreProductionPlanRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Planning & Purchase: raise a batch, see what the stores can give, raise
 * the material requests.
 */
class ProductionPlanController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'planned_quantity', 'planned_start_date', 'created_at'];

    public function __construct(private readonly ProductionPlanService $plans) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProductionPlan::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status']);

        $query = ProductionPlan::query()
            ->with(['formula:id,code,name', 'product:id,code,name', 'plannedUom:id,code', 'createdBy:id,name'])
            ->withCount([
                'lines as short_lines_count' => fn ($q) => $q->where('shortage_quantity', '>', 0),
                'materialRequests',
            ])
            ->search($table->search);

        if ($status = $table->filter('status')) {
            $query->where('status', $status);
        }

        return Inertia::render('plans/index', [
            'plans' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => $table->toArray(),
            'statuses' => array_map(static fn (ProductionPlanStatus $s): array => ['value' => $s->value, 'label' => $s->label()], ProductionPlanStatus::cases()),
            'can' => ['create' => $request->user()->can('create', ProductionPlan::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ProductionPlan::class);

        return Inertia::render('plans/create', [
            'formulas' => Formula::query()
                ->active()
                ->with(['product:id,code,name,net_content,net_content_uom_id', 'product.netContentUom:id,code', 'activeVersion:id,version_number,batch_uom_id', 'activeVersion.batchUom:id,code'])
                ->orderBy('name')
                ->get()
                ->map(static fn (Formula $f): array => [
                    'value' => $f->id,
                    'label' => "{$f->name} ({$f->code})",
                    'product' => $f->product?->name,
                    'net_content' => $f->product?->net_content,
                    'net_content_uom' => $f->product?->netContentUom?->code,
                    'version' => $f->activeVersion?->version_number,
                    'batch_uom_id' => $f->activeVersion?->batch_uom_id,
                    'batch_uom' => $f->activeVersion?->batchUom?->code,
                ])->all(),
            'uoms' => Uom::query()->active()->whereIn('dimension', ['mass', 'volume'])->orderBy('dimension')->orderBy('code')->get(['id', 'code', 'name', 'dimension'])
                ->map(static fn (Uom $u): array => ['value' => $u->id, 'label' => "{$u->code} — {$u->name}", 'dimension' => $u->dimension->value])->all(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreProductionPlanRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductionPlan::class);

        try {
            $plan = $this->plans->create($request->validated(), $request->user()->id);
        } catch (PlanningException|RuntimeException $e) {
            return back()->withInput()->withErrors(['formula_id' => $e->getMessage()]);
        }

        $message = $plan->hasShortage()
            ? "{$plan->number} checked: some materials are short. Review the lines and raise the material requests."
            : "{$plan->number} checked: every material is available.";

        return redirect()->route('plans.show', $plan)->withToast($plan->hasShortage() ? 'warning' : 'success', $message);
    }

    public function show(Request $request, ProductionPlan $plan): Response
    {
        $this->authorize('view', $plan);

        $plan->load([
            'formula:id,code,name',
            'formulaVersion:id,version_number,batch_size,batch_uom_id',
            'formulaVersion.batchUom:id,code',
            'product:id,code,name,net_content,net_content_uom_id',
            'product.netContentUom:id,code',
            'plannedUom:id,code',
            'createdBy:id,name',
            'lines.item:id,code,name,type,reorder_level,minimum_stock',
            'lines.uom:id,code',
            'materialRequests.warehouse:id,code,name',
        ]);

        $lines = $plan->lines->map(static fn (ProductionPlanLine $line): array => [
            'id' => $line->id,
            'line_no' => $line->line_no,
            'store_kind' => $line->store_kind->value,
            'item_id' => $line->item_id,
            'item_code' => $line->item->code,
            'item_name' => $line->item->name,
            'item_type' => $line->item->type->value,
            'uom' => $line->uom->code,
            'percentage' => $line->percentage,
            'is_qs' => $line->is_qs,
            'as_required' => $line->as_required,
            'required' => $line->required_quantity,
            'available' => $line->available_quantity,
            'shortage' => $line->shortage_quantity,
            'restock' => $line->restock_quantity,
            'level_now' => $line->level_now->value,
            'level_after' => $line->level_after->value,
            'reorder_level' => $line->item->reorder_level,
            'minimum_stock' => $line->item->minimum_stock,
            'notes' => $line->notes ?? [],
        ]);

        return Inertia::render('plans/show', [
            'plan' => $plan,
            'rawMaterials' => $lines->where('store_kind', 'raw_material')->values()->all(),
            'packaging' => $lines->where('store_kind', 'packaging')->values()->all(),
            'requests' => $plan->materialRequests->map(static fn (MaterialRequest $r): array => [
                'id' => $r->id,
                'number' => $r->number,
                'store_kind' => $r->store_kind->value,
                'store_label' => $r->store_kind->label(),
                'warehouse' => $r->warehouse?->code,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'needed_by' => $r->needed_by?->toDateString(),
            ])->all(),
            'can' => [
                'check' => $request->user()->can('check', $plan) && $plan->status->canBeChecked(),
                'request' => $request->user()->can('request', $plan) && $plan->status === ProductionPlanStatus::Checked,
                'cancel' => $request->user()->can('cancel', $plan) && $plan->status->isOpen(),
            ],
        ]);
    }

    public function check(Request $request, ProductionPlan $plan): RedirectResponse
    {
        $this->authorize('check', $plan);

        try {
            $plan = $this->plans->check($plan);
        } catch (PlanningException|RuntimeException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast($plan->hasShortage() ? 'warning' : 'success', $plan->hasShortage()
            ? "{$plan->number} re-checked against the stores: some materials are still short."
            : "{$plan->number} re-checked: every material is available.");
    }

    public function requests(Request $request, ProductionPlan $plan): RedirectResponse
    {
        $this->authorize('request', $plan);

        try {
            $requests = $this->plans->generateRequests($plan, $request->user()->id, $request->input('needed_by'));
        } catch (PlanningException|RuntimeException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        $numbers = $requests->pluck('number')->implode(', ');

        return back()->withToast('success', $requests->count() === 1
            ? "Material request {$numbers} raised."
            : "Material requests raised: {$numbers}.");
    }

    public function cancel(Request $request, ProductionPlan $plan): RedirectResponse
    {
        $this->authorize('cancel', $plan);

        try {
            $this->plans->cancel($plan, $request->user()->id, $request->input('reason'));
        } catch (PlanningException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', "{$plan->number} cancelled, along with any open material requests.");
    }
}
