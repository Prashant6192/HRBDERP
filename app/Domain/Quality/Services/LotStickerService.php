<?php

declare(strict_types=1);

namespace App\Domain\Quality\Services;

use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Quality\Models\QcInspection;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use InvalidArgumentException;

/**
 * The label that goes on the drum.
 *
 * 80 mm wide for the store's label printer, carrying the four things the
 * person picking it up needs to see at arm's length: that quality passed it,
 * which batch it is, when it arrived, and when it dies. The size lives in
 * config('erp.labels.batch_sticker').
 */
class LotStickerService
{
    public function render(InventoryLot $lot): PdfDocument
    {
        if (! $lot->qc_status->isReleasable()) {
            throw new InvalidArgumentException(
                "Batch {$lot->batch_number} has not been released by quality ({$lot->qc_status->label()}); no sticker can be printed."
            );
        }

        $lot->loadMissing(['item.stockUom', 'vendor', 'qcDecidedBy']);

        $inspection = QcInspection::query()
            ->where('lot_id', $lot->id)
            ->latest('id')
            ->first();

        $size = config('erp.labels.batch_sticker', ['width' => 80, 'height' => 50]);
        $points = fn (float $mm): float => $mm * 72 / 25.4;

        return Pdf::loadView('pdf.lot-sticker', [
            'lot' => $lot,
            'item' => $lot->item,
            'inspection' => $inspection,
            'company' => config('erp.company.name'),
            'width' => $size['width'],
            'height' => $size['height'],
        ])->setPaper([0, 0, $points((float) $size['width']), $points((float) $size['height'])], 'portrait');
    }

    public function filename(InventoryLot $lot): string
    {
        return 'sticker-'.$lot->batch_number.'.pdf';
    }
}
