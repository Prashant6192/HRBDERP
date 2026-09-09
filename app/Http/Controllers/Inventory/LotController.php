<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Inventory\Models\InventoryTransactionLine;
use App\Domain\MasterData\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Support\Tables\TableQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LotController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['batch_number', 'received_at', 'expiry_at', 'qc_status', 'created_at'];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', InventoryLot::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['qc_status', 'type']);

        $query = InventoryLot::query()
            ->with(['item:id,code,name,type,stock_uom_id', 'item.stockUom:id,code', 'vendor:id,name'])
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
            'vendor:id,name', 'qcDecidedBy:id,name',
            'balances.warehouse:id,code,name,is_quarantine',
        ]);

        $movements = InventoryTransactionLine::query()
            ->with(['transaction:id,number,type,transacted_at,reason', 'warehouse:id,code'])
            ->where('lot_id', $lot->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('lots/show', [
            'lot' => $lot,
            'movements' => $movements,
            'can' => ['sticker' => $lot->qc_status->isReleasable() && $request->user()->can('printSticker', $lot)],
        ]);
    }
}
