<?php

declare(strict_types=1);

namespace App\Http\Controllers\Contract;

use App\Domain\Contract\Enums\ManufacturingType;
use App\Domain\Contract\Models\Client;
use App\Domain\Contract\Models\ClientArtwork;
use App\Domain\Contract\Models\ClientQcSpec;
use App\Domain\Contract\Services\ArtworkService;
use App\Domain\Contract\Services\ClientMaterialReconciliationService;
use App\Domain\Contract\Services\ClientProfitabilityService;
use App\Domain\Contract\Services\JobCostingService;
use App\Domain\Formulation\Models\Formula;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Manufacturing\Enums\ManufacturingOrderStatus;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Planning\Enums\ProductionPlanStatus;
use App\Domain\Planning\Models\ProductionPlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Contract\StoreClientRequest;
use App\Http\Requests\Contract\UpdateClientRequest;
use App\Support\Tables\TableQuery;
use Brick\Math\BigDecimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The third-party clients the company manufactures for, and everything of
 * theirs the ordinary workflow has tagged: products, formulas, jobs,
 * material, finished goods, artwork and QC specifications.
 */
class ClientController extends Controller
{
    private const array SORTABLE = ['code', 'name', 'billing_city', 'payment_terms_days', 'is_active', 'created_at'];

    public function __construct(
        private readonly JobCostingService $costing,
        private readonly ClientMaterialReconciliationService $reconciliation,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['status']);

        $query = Client::query()
            ->search($table->search)
            ->withCount([
                'orders as open_jobs_count' => fn ($q) => $q->open(),
                'products',
            ]);

        if ($status = $table->filter('status')) {
            match ($status) {
                'active' => $query->where('is_active', true),
                'inactive' => $query->where('is_active', false),
                default => null,
            };
        }

        return Inertia::render('clients/index', [
            'clients' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'name')),
            'table' => $table->toArray(),
            'can' => ['create' => $request->user()->can('create', Client::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Client::class);

        return Inertia::render('clients/create', ['nextCode' => Client::nextCode()]);
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $client = Client::create([
            ...$request->validated(),
            'code' => Client::nextCode(),
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return redirect()->route('clients.show', $client)->withToast('success', "Client {$client->code} {$client->name} added.");
    }

    public function show(Request $request, Client $client): Response
    {
        $this->authorize('view', $client);

        $orders = ManufacturingOrder::query()
            ->where('client_id', $client->id)
            ->with(['product:id,code,name', 'plannedUom:id,code', 'outputLot:id,batch_number,qc_status', 'plan:id,number', 'facility:id,name'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $costed = $orders
            ->filter(fn (ManufacturingOrder $o) => $o->status === ManufacturingOrderStatus::Completed)
            ->map(fn (ManufacturingOrder $o) => $this->costing->forOrder($o));

        $sum = fn (string $key): string => $costed->reduce(fn (BigDecimal $c, array $row) => $c->plus(BigDecimal::of($row[$key])), BigDecimal::zero())->__toString();

        $finishedGoods = InventoryLot::query()
            ->where('owner_client_id', $client->id)
            ->whereHas('item', fn ($q) => $q->where('type', ItemType::FinishedGood->value))
            ->with(['item:id,code,name,stock_uom_id', 'item.stockUom:id,code', 'balances.warehouse:id,code,name,is_quarantine'])
            ->orderByDesc('id')
            ->get()
            ->map(function (InventoryLot $lot): ?array {
                $onHand = $lot->balances->reduce(fn (BigDecimal $c, $b) => $c->plus(BigDecimal::of($b->on_hand)), BigDecimal::zero());

                if (! $onHand->isPositive()) {
                    return null;
                }

                return [
                    'lot_id' => $lot->id,
                    'batch_number' => $lot->batch_number,
                    'product' => $lot->item->name,
                    'product_code' => $lot->item->code,
                    'on_hand' => $onHand->__toString(),
                    'uom' => $lot->item->stockUom?->code,
                    'qc_status' => $lot->qc_status->value,
                    'expiry_at' => $lot->expiry_at?->toDateString(),
                    'manufactured_at' => $lot->manufactured_at?->toDateString(),
                    'stores' => $lot->balances->filter(fn ($b) => (float) $b->on_hand > 0)->map(fn ($b) => $b->warehouse?->code)->filter()->unique()->values()->all(),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return Inertia::render('clients/show', [
            'client' => $client,
            'products' => $client->products()->orderBy('name')->get(['id', 'code', 'name', 'is_active'])->all(),
            'formulas' => Formula::query()->where('client_id', $client->id)->orderBy('name')->get(['id', 'code', 'name', 'status', 'ownership'])
                ->map(fn (Formula $f) => ['id' => $f->id, 'code' => $f->code, 'name' => $f->name, 'status' => $f->status->value, 'ownership' => $f->ownership->label()])->all(),
            'plans' => ProductionPlan::query()
                ->where('client_id', $client->id)
                ->whereIn('status', [ProductionPlanStatus::Draft->value, ProductionPlanStatus::Checked->value, ProductionPlanStatus::Requested->value])
                ->with(['product:id,name', 'plannedUom:id,code'])
                ->withCount(['lines as awaiting_client_count' => fn ($q) => $q->where('source', 'client')->where('shortage_quantity', '>', 0)])
                ->orderByDesc('id')
                ->get()
                ->map(fn (ProductionPlan $p) => [
                    'id' => $p->id,
                    'number' => $p->number,
                    'product' => $p->client_product_name ?? $p->product?->name,
                    'batch' => rtrim(rtrim($p->planned_quantity, '0'), '.').' '.($p->plannedUom?->code ?? ''),
                    'status' => $p->status->value,
                    'status_label' => $p->status->label(),
                    'client_po_ref' => $p->client_po_ref,
                    'required_delivery_at' => $p->required_delivery_at?->toDateString(),
                    'awaiting_client_material' => (int) $p->awaiting_client_count,
                ])->all(),
            'orders' => $orders->map(fn (ManufacturingOrder $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'plan' => $o->plan?->number,
                'product' => $o->product?->name,
                'facility' => $o->facility?->name,
                'batch' => rtrim(rtrim($o->planned_quantity, '0'), '.').' '.($o->plannedUom?->code ?? '').($o->planned_units ? " · {$o->planned_units} units" : ''),
                'status' => $o->status->value,
                'status_label' => $o->status->label(),
                'client_po_ref' => $o->client_po_ref,
                'required_delivery_at' => $o->required_delivery_at?->toDateString(),
                'completed_at' => $o->completed_at?->toIso8601String(),
                'output_units' => $o->output_units,
                'batch_number' => $o->outputLot?->batch_number,
                'qc_status' => $o->outputLot?->qc_status?->value,
            ])->all(),
            'material' => $this->reconciliation->rows($client),
            'profitability' => $request->user()->can('costing.view') ? app(ClientProfitabilityService::class)->forClient($client) : null,
            'finishedGoods' => $finishedGoods,
            'costing' => [
                'jobs' => $costed->count(),
                'chargeable' => $sum('chargeable'),
                'total' => $sum('total'),
                'margin' => $sum('margin'),
                'client_material_value' => $sum('client_material_value'),
            ],
            'artworks' => ClientArtwork::query()->where('client_id', $client->id)->with(['product:id,name', 'client:id,code,name', 'approvedByUser:id,name'])->orderByDesc('id')->get()
                ->map(fn (ClientArtwork $a) => app(ArtworkService::class)->serialize($a))->all(),
            'qcSpecs' => ClientQcSpec::query()->where('client_id', $client->id)->with('product:id,code,name')->get()
                ->map(fn (ClientQcSpec $s) => [
                    'id' => $s->id,
                    'product_id' => $s->product_id,
                    'product' => $s->product?->name,
                    'product_code' => $s->product?->code,
                    'parameters' => $s->parameters,
                    'notes' => $s->notes,
                ])->all(),
            'productOptions' => Item::query()->where('type', ItemType::FinishedGood->value)->active()->orderBy('name')->get(['id', 'code', 'name', 'client_id'])
                ->map(fn (Item $i) => ['value' => $i->id, 'label' => "{$i->code} — {$i->name}", 'own' => $i->client_id === $client->id])
                ->sortByDesc('own')->values()->all(),
            'artworkKinds' => ClientArtwork::KINDS,
            'manufacturingTypes' => ManufacturingType::options(),
            'can' => [
                'update' => $request->user()->can('update', $client),
                'delete' => $request->user()->can('delete', $client),
                'plan' => $request->user()->can('planning.create'),
                'viewCosting' => $request->user()->can('costing.view'),
            ],
        ]);
    }

    public function edit(Client $client): Response
    {
        $this->authorize('update', $client);

        return Inertia::render('clients/edit', ['client' => $client]);
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->update([...$request->validated(), 'updated_by' => $request->user()->id]);

        return redirect()->route('clients.show', $client)->withToast('success', "Client {$client->code} updated.");
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $inUse = $client->plans()->exists() || $client->orders()->exists() || $client->lots()->exists() || $client->products()->exists() || $client->formulas()->exists();

        if ($inUse) {
            return back()->withToast('error', "{$client->name} has jobs, stock, products or formulas on file and cannot be removed. Mark the client inactive instead.");
        }

        $client->delete();

        return redirect()->route('clients.index')->withToast('success', "Client {$client->code} removed.");
    }
}
