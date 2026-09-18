<?php

declare(strict_types=1);

namespace App\Http\Controllers\Planning;

use App\Domain\Contract\Enums\ManufacturingType;
use App\Domain\Contract\Enums\MaterialSource;
use App\Domain\Contract\Models\Client;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Exceptions\PlanningException;
use App\Domain\Planning\Models\MaterialRequest;
use App\Domain\Planning\Models\ProductionPlan;
use App\Domain\Planning\Models\ProductionPlanLine;
use App\Domain\Planning\Services\ProductionPlanService;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Planning\StoreProductionPlanRequest;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * Planning & Purchase: raise a batch, see what the stores can give, raise
 * the material requests.
 */
class ProductionPlanController extends Controller
{
    private const array SORTABLE = ['number', 'status', 'planned_quantity', 'planned_start_date', 'created_at'];

    public function __construct(
        private readonly ProductionPlanService $plans,
        private readonly FacilityAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ProductionPlan::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status', 'type', 'client']);

        $query = ProductionPlan::query()
            ->with(['formula:id,code,name', 'product:id,code,name', 'plannedUom:id,code', 'createdBy:id,name', 'client:id,code,name'])
            ->withCount([
                'lines as short_lines_count' => fn ($q) => $q->where('shortage_quantity', '>', 0),
                'materialRequests',
            ])
            ->search($table->search);

        if ($status = $table->filter('status')) {
            $query->where('status', $status);
        }

        if ($type = $table->filter('type')) {
            $query->where('manufacturing_type', $type);
        }

        if ($client = $table->filter('client')) {
            $query->where('client_id', (int) $client);
        }

        return Inertia::render('plans/index', [
            'plans' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => $table->toArray(),
            'statuses' => array_map(static fn (ProductionPlanStatus $s): array => ['value' => $s->value, 'label' => $s->label()], ProductionPlanStatus::cases()),
            'types' => ManufacturingType::options(),
            'clients' => $this->clientOptions(),
            'can' => ['create' => $request->user()->can('create', ProductionPlan::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', ProductionPlan::class);

        return Inertia::render('plans/create', [
            'formulas' => Formula::query()
                ->active()
                ->with([
                    'product:id,code,name,net_content,net_content_uom_id,client_id', 'product.netContentUom:id,code',
                    'product.packagingLines.packagingMaterial:id,code,name',
                    'activeVersion:id,version_number,batch_uom_id', 'activeVersion.batchUom:id,code',
                    'activeVersion.ingredients.item:id,code,name', 'client:id,name',
                ])
                ->orderBy('name')
                ->get()
                ->map(static fn (Formula $f): array => [
                    'value' => $f->id,
                    'label' => "{$f->name} ({$f->code})",
                    'product' => $f->product?->name,
                    'product_client_id' => $f->product?->client_id,
                    'net_content' => $f->product?->net_content,
                    'net_content_uom' => $f->product?->netContentUom?->code,
                    'version' => $f->activeVersion?->version_number,
                    'batch_uom_id' => $f->activeVersion?->batch_uom_id,
                    'batch_uom' => $f->activeVersion?->batchUom?->code,
                    'ownership' => $f->ownership->value,
                    'ownership_label' => $f->ownership->label(),
                    'client_id' => $f->client_id,
                    'client' => $f->client?->name,
                    // What the batch will need, so a third-party plan can say
                    // which of it the client supplies.
                    'materials' => [
                        ...($f->activeVersion?->ingredients->map(static fn ($i): array => ['value' => $i->item_id, 'label' => $i->item?->name ?? "#{$i->item_id}", 'kind' => 'raw_material'])->all() ?? []),
                        ...($f->product?->packagingLines->map(static fn ($l): array => ['value' => $l->packaging_material_id, 'label' => $l->packagingMaterial?->name ?? "#{$l->packaging_material_id}", 'kind' => 'packaging'])->all() ?? []),
                    ],
                ])->all(),
            'clients' => $this->clientOptions(),
            'manufacturingTypes' => ManufacturingType::options(),
            'materialSources' => MaterialSource::options(),
            'uoms' => Uom::query()->active()->whereIn('dimension', ['mass', 'volume'])->orderBy('dimension')->orderBy('code')->get(['id', 'code', 'name', 'dimension'])
                ->map(static fn (Uom $u): array => ['value' => $u->id, 'label' => "{$u->code} — {$u->name}", 'dimension' => $u->dimension->value])->all(),
            'facilities' => $this->access->scopeFacilities($request->user(), Facility::query()->active()->manufacturing())->ordered()->get(['id', 'code', 'name', 'city'])
                ->map(static fn (Facility $f): array => ['value' => $f->id, 'label' => $f->name, 'description' => $f->city])->all(),
            'today' => now()->toDateString(),
        ]);
    }

    public function store(StoreProductionPlanRequest $request): RedirectResponse
    {
        $this->authorize('create', ProductionPlan::class);

        $data = $request->validated();

        if (! empty($data['facility_id'])) {
            $this->access->assertCanWorkAt($request->user(), Facility::query()->findOrFail($data['facility_id']));
        }

        try {
            $plan = $this->plans->create($data, $request->user()->id);
        } catch (PlanningException|RuntimeException $e) {
            return back()->withInput()->withErrors(['formula_id' => $e->getMessage()]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['formula_id' => $this->unexpected($e, $request, $data)]);
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
            'facility:id,code,name',
            'client:id,code,name',
            'formula:id,code,name,ownership,client_id',
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
            // A master deleted after the plan was checked leaves the line
            // readable rather than blanking the page.
            'item_code' => $line->item?->code ?? '—',
            'item_name' => $line->item?->name ?? 'Material no longer on file',
            'item_type' => $line->item?->type->value ?? 'raw_material',
            'uom' => $line->uom?->code ?? '',
            'percentage' => $line->percentage,
            'is_qs' => $line->is_qs,
            'as_required' => $line->as_required,
            'required' => $line->required_quantity,
            'available' => $line->available_quantity,
            'shortage' => $line->shortage_quantity,
            'restock' => $line->restock_quantity,
            'level_now' => $line->level_now->value,
            'level_after' => $line->level_after->value,
            'source' => $line->source,
            'reorder_level' => $line->item?->reorder_level,
            'minimum_stock' => $line->item?->minimum_stock,
            'notes' => $line->notes ?? [],
            'available_elsewhere' => $line->available_elsewhere ?? [],
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
            'manufacturingOrders' => ManufacturingOrder::query()
                ->where('production_plan_id', $plan->id)
                ->orderBy('id')
                ->get(['id', 'number', 'status', 'started_at', 'completed_at', 'output_lot_id'])
                ->map(static fn (ManufacturingOrder $o): array => [
                    'id' => $o->id,
                    'number' => $o->number,
                    'status' => $o->status->value,
                    'status_label' => $o->status->label(),
                    'started_at' => $o->started_at?->toIso8601String(),
                    'completed_at' => $o->completed_at?->toIso8601String(),
                ])->all(),
            'can' => [
                'check' => $request->user()->can('check', $plan) && $plan->status->canBeChecked(),
                'request' => $request->user()->can('request', $plan) && $plan->status === ProductionPlanStatus::Checked,
                'cancel' => $request->user()->can('cancel', $plan) && $plan->status->isOpen(),
                'manufacture' => $request->user()->can('create', ManufacturingOrder::class)
                    && in_array($plan->status, [ProductionPlanStatus::Checked, ProductionPlanStatus::Requested], strict: true)
                    && ! ManufacturingOrder::query()->where('production_plan_id', $plan->id)->open()->exists(),
            ],
        ]);
    }

    /**
     * Something the planning code did not expect.
     *
     * The person gets a sentence they can act on and a reference to quote;
     * the detail goes to the log, where it can be read without asking them
     * to reproduce it.
     *
     * @param  array<string, mixed>  $context
     */
    private function unexpected(Throwable $e, Request $request, array $context): string
    {
        $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

        Log::error("Planning failed unexpectedly [{$reference}]", [
            'reference' => $reference,
            'user_id' => $request->user()?->id,
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
            'context' => $context,
        ]);

        $message = "The plan could not be checked because of an unexpected fault (reference {$reference}). "
            .'It has been logged with the reference above. Nothing was saved, so trying again is safe.';

        // The system administrator is the one who can act on a fault like
        // this, and reading a server log is not something they should have
        // to do to find out what it was. Nobody else sees the internals.
        if ($request->user()?->isSuperAdmin() === true) {
            $message .= sprintf(
                ' [%s: %s at %s:%d]',
                class_basename($e),
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            );
        }

        return $message;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function clientOptions(): array
    {
        return Client::query()->active()->orderBy('name')->get(['id', 'code', 'name'])
            ->map(static fn (Client $c): array => ['value' => $c->id, 'label' => "{$c->name} ({$c->code})"])
            ->all();
    }

    public function check(Request $request, ProductionPlan $plan): RedirectResponse
    {
        $this->authorize('check', $plan);

        try {
            $plan = $this->plans->check($plan);
        } catch (PlanningException|RuntimeException $e) {
            return back()->withToast('error', $e->getMessage());
        } catch (Throwable $e) {
            return back()->withToast('error', $this->unexpected($e, $request, ['plan' => $plan->number]));
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
        } catch (Throwable $e) {
            return back()->withToast('error', $this->unexpected($e, $request, ['plan' => $plan->number]));
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
