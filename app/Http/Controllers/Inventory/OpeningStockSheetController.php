<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory;

use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\Inventory\Services\OpeningStockSheetService;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The counting sheet behind opening stock.
 *
 * The store counts what is on the shelf into the template and uploads it;
 * every row is matched to the material on file before anything is booked.
 * Which materials a sheet may name follows the store it is for, so a
 * packaging store cannot take a raw material by mistake.
 */
class OpeningStockSheetController extends Controller
{
    public function __construct(private readonly OpeningStockSheetService $sheets) {}

    public function template(Request $request, Warehouse $warehouse): HttpResponse
    {
        $this->authorise($request, $warehouse);
        $kind = $this->kindFor($warehouse);

        return response($this->sheets->template($kind), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="opening-stock-'.$warehouse->code.'.xlsx"',
        ]);
    }

    /**
     * A filled-in sheet, matched to the masters and handed back to the
     * screen to be looked over before anything is booked.
     */
    public function parse(Request $request, Warehouse $warehouse): JsonResponse
    {
        $this->authorise($request, $warehouse);

        $request->validate([
            'sheet' => ['required', 'file', 'max:10240', Rule::file()->extensions(['xlsx', 'xls', 'csv'])],
        ]);

        try {
            $parsed = $this->sheets->parse($request->file('sheet')->getRealPath(), $this->kindFor($warehouse));
        } catch (OpeningStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($parsed);
    }

    /**
     * Which masters belong in this store's sheet.
     */
    private function kindFor(Warehouse $warehouse): string
    {
        return match ($warehouse->type) {
            WarehouseType::Packaging, WarehouseType::PackagingStaging => 'packaging',
            WarehouseType::FinishedGoods, WarehouseType::Marketplace => 'finished_goods',
            default => 'raw_material',
        };
    }

    private function authorise(Request $request, Warehouse $warehouse): void
    {
        $facility = $warehouse->facility ?? abort(404);
        $this->authorize('bookOpeningStock', $facility instanceof Facility ? $facility : abort(404));
    }
}
