<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\InventoryLot;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use InvalidArgumentException;

/**
 * The A5 label on every outer carton of a finished batch: product, net
 * quantity, batch, dates, gross weight and the box number. The finished
 * goods store records how the batch was boxed once; every print after that
 * comes out identical, so box 7 is always box 7.
 */
class CartonLabelService
{
    /**
     * @param  array{boxes: int, units_per_box: int, gross_weight_kg: string|null, start_box: int, net_quantity: string|null, remarks: string|null}  $plan
     */
    public function plan(InventoryLot $lot, array $plan, int $userId): InventoryLot
    {
        if ($plan['boxes'] < 1 || $plan['boxes'] > 5000) {
            throw new InvalidArgumentException('Enter between 1 and 5000 boxes.');
        }

        $lot->forceFill(['carton_plan' => [
            'boxes' => (int) $plan['boxes'],
            'units_per_box' => (int) $plan['units_per_box'],
            'gross_weight_kg' => $plan['gross_weight_kg'] !== null && $plan['gross_weight_kg'] !== '' ? (string) $plan['gross_weight_kg'] : null,
            'start_box' => (int) ($plan['start_box'] ?? 1),
            'net_quantity' => $plan['net_quantity'] ?? null,
            'remarks' => $plan['remarks'] ?? null,
            'planned_by' => $userId,
            'planned_at' => now()->toIso8601String(),
        ]])->save();

        return $lot;
    }

    public function render(InventoryLot $lot): PdfDocument
    {
        if (! $lot->qc_status->isReleasable()) {
            throw new InvalidArgumentException("Batch {$lot->batch_number} has not been released by quality; carton labels cannot be printed.");
        }

        $plan = $lot->carton_plan;

        if (! is_array($plan) || empty($plan['boxes'])) {
            throw new InvalidArgumentException('Record how the batch was boxed before printing carton labels.');
        }

        $lot->loadMissing(['item.stockUom', 'item.netContentUom']);
        $item = $lot->item;

        $netContent = $plan['net_quantity'] ?? null;

        if (($netContent === null || $netContent === '') && $item->net_content !== null) {
            $netContent = rtrim(rtrim(number_format((float) $item->net_content, 3, '.', ''), '0'), '.').' '.($item->netContentUom?->code ?? '');
        }

        $boxes = [];
        $start = (int) ($plan['start_box'] ?? 1);

        for ($i = 0; $i < (int) $plan['boxes']; $i++) {
            $boxes[] = $start + $i;
        }

        return Pdf::loadView('pdf.carton-labels', [
            'lot' => $lot,
            'item' => $item,
            'plan' => $plan,
            'boxes' => $boxes,
            'lastBox' => $start + count($boxes) - 1,
            'netContent' => $netContent ?: '—',
            'company' => config('erp.company.name'),
        ])->setPaper('a5', 'landscape');
    }

    public function filename(InventoryLot $lot): string
    {
        return 'cartons-'.$lot->batch_number.'.pdf';
    }
}
