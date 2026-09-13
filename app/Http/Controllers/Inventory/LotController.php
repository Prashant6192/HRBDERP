<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransactionLine;
use App\Domain\Inventory\Services\CartonLabelService;
use App\Domain\Inventory\Services\RecallTraceService;
use App\Domain\MasterData\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LotController extends Controller
{
    public function __construct(private readonly CartonLabelService $cartons) {}

    /**
     * @var list<string>
     */
    private const SORTABLE = ['batch_number', 'received_at', 'expiry_at', 'qc_status', 'created_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InventoryLot::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['qc_status', 'type']);

        $query = InventoryLot::query()
            ->with(['item:id,code,name,type,stock_uom_id', 'item.stockUom:id,code', 'vendor:id,name', 'ownerClient:id,code,name'])
            ->withSum('balances as on_hand', 'on_hand');

        if ($table->search !== '') {
            $term = $table->search;
            $query->where(function ($q) use ($term): void {
                $q->where('batch_number', 'ilike', "%{$term}%")
                    ->orWhere('supplier_batch_ref', 'ilike', "%{$term}%")
                    ->orWhereHas('item', fn ($i) => $i->where('name', 'ilike', "%{$term}%")->orWhere('code', 'ilike', "%{$term}%"));
            });
        }

        if ($status = $table->filter('qc_status')) {
            $query->where('qc_status', $status);
        }

        if ($type = $table->filter('type')) {
            $query->whereHas('item', fn ($i) => $i->where('type', $type));
        }

        return Inertia::render('lots/index', [
            'lots' => $table->paginate($table->applySorting($query, self::SORTABLE, fallback: 'created_at')),
            'table' => $table->toArray(),
            'statuses' => array_map(fn (LotQcStatus $s) => ['value' => $s->value, 'label' => $s->label()], LotQcStatus::cases()),
            'types' => array_map(fn (ItemType $t) => ['value' => $t->value, 'label' => $t->label()], ItemType::cases()),
        ]);
    }

    public function show(Request $request, InventoryLot $lot): Response
    {
        $this->authorize('view', $lot);

        $lot->load([
            'item:id,code,name,type,stock_uom_id', 'item.stockUom:id,code,display_scale',
            'vendor:id,name', 'qcDecidedBy:id,name', 'ownerClient:id,code,name',
            'balances.warehouse:id,code,name,is_quarantine',
        ]);

        $movements = InventoryTransactionLine::query()
            ->with(['transaction:id,number,type,transacted_at,reason,reverses_transaction_id', 'transaction.reversal:id,number,reverses_transaction_id', 'warehouse:id,code'])
            ->where('lot_id', $lot->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('lots/show', [
            'lot' => $lot,
            'movements' => $movements,
            'can' => [
                'sticker' => $lot->qc_status->isReleasable() && $request->user()->can('printSticker', $lot),
                'cartons' => $lot->qc_status->isReleasable() && $lot->item?->type === ItemType::FinishedGood && $request->user()->can('printSticker', $lot),
                // Corrections are reversals, never edits.
                'reverse' => $request->user()->can('inventory.reverse'),
                'trace' => $request->user()->can('inventory.view'),
            ],
            'cartonPlan' => $lot->carton_plan,
        ]);
    }

    /**
     * Recall management: everything this lot touched, forwards and backwards.
     */
    public function trace(Request $request, InventoryLot $lot): Response
    {
        $this->authorize('view', $lot);

        return Inertia::render('lots/trace', [
            'lot' => $lot->load(['item:id,code,name,type']),
            'trace' => app(RecallTraceService::class)->trace($lot),
        ]);
    }

    /**
     * How a finished batch is boxed: the finished goods store records it
     * once, then prints one A5 label per carton.
     */
    public function cartons(Request $request, InventoryLot $lot): Response
    {
        Gate::authorize('printSticker', $lot);

        $lot->load(['item:id,code,name,type,net_content,net_content_uom_id,mrp', 'item.netContentUom:id,code']);

        return Inertia::render('lots/cartons', [
            'lot' => $lot,
            'plan' => $lot->carton_plan,
            'printable' => $lot->qc_status->isReleasable(),
        ]);
    }

    public function storeCartons(Request $request, InventoryLot $lot): RedirectResponse
    {
        Gate::authorize('printSticker', $lot);

        $data = $request->validate([
            'boxes' => ['required', 'integer', 'min:1', 'max:5000'],
            'units_per_box' => ['required', 'integer', 'min:1', 'max:100000'],
            'gross_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'start_box' => ['nullable', 'integer', 'min:1'],
            'net_quantity' => ['nullable', 'string', 'max:64'],
            'remarks' => ['nullable', 'string', 'max:160'],
        ]);

        try {
            $this->cartons->plan($lot, [
                'boxes' => (int) $data['boxes'],
                'units_per_box' => (int) $data['units_per_box'],
                'gross_weight_kg' => $data['gross_weight_kg'] ?? null,
                'start_box' => (int) ($data['start_box'] ?? 1),
                'net_quantity' => $data['net_quantity'] ?? null,
                'remarks' => $data['remarks'] ?? null,
            ], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['boxes' => $e->getMessage()]);
        }

        return back()->withToast('success', "Carton plan saved for {$lot->batch_number}: {$data['boxes']} box".((int) $data['boxes'] === 1 ? '' : 'es').' ready to print.');
    }

    public function printCartons(InventoryLot $lot): HttpResponse
    {
        Gate::authorize('printSticker', $lot);

        try {
            $pdf = $this->cartons->render($lot);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return $pdf->stream($this->cartons->filename($lot));
    }
}
