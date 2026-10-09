<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\MasterData\Exceptions\ItemNamesSheetException;
use App\Domain\MasterData\Services\ItemNamesSheetService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Stock → a raw material store → the ingredient list in Excel: download
 * it, correct codes and names, upload it, look over the changes, apply.
 */
class ItemNamesSheetController extends Controller
{
    public function __construct(private readonly ItemNamesSheetService $sheets) {}

    public function download(Request $request, Warehouse $warehouse): HttpResponse
    {
        $this->rawMaterialStore($warehouse);
        abort_unless($request->user()->can('raw_material.view'), 403);

        return response($this->sheets->download($warehouse), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="ingredients-'.$warehouse->code.'-'.now()->format('Y-m-d').'.xlsx"',
        ]);
    }

    /**
     * An edited sheet, read and checked, handed back to be looked over.
     */
    public function read(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->rawMaterialStore($warehouse);
        abort_unless($request->user()->can('raw_material.edit'), 403);

        $request->validate([
            'sheet' => ['required', 'file', 'max:10240', Rule::file()->extensions(['xlsx', 'xls', 'csv'])],
        ]);

        try {
            return response()->json($this->sheets->read($request->file('sheet')->getRealPath()));
        } catch (ItemNamesSheetException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function apply(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->rawMaterialStore($warehouse);
        abort_unless($request->user()->can('raw_material.edit'), 403);

        $data = $request->validate([
            'changes' => ['required', 'array', 'min:1', 'max:5000'],
            'changes.*.id' => ['required', 'integer', 'distinct'],
            'changes.*.code' => ['required', 'string', 'max:255'],
            'changes.*.name' => ['required', 'string', 'max:1000'],
        ]);

        $changes = [];

        foreach ($data['changes'] as $change) {
            $changes[(int) $change['id']] = ['code' => $change['code'], 'name' => $change['name']];
        }

        try {
            $count = $this->sheets->apply($changes, $request->user());
        } catch (ItemNamesSheetException $e) {
            return back()->withToast('error', $e->getMessage());
        }

        return back()->withToast('success', $count === 1 ? '1 ingredient updated.' : "{$count} ingredients updated.");
    }

    private function rawMaterialStore(Warehouse $warehouse): void
    {
        abort_unless($warehouse->type === WarehouseType::RawMaterial, 404);
    }
}
