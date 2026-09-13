<?php

declare(strict_types=1);

namespace App\Domain\Quality\Services;

use App\Domain\Inventory\Enums\LotQcStatus;
use App\Domain\Quality\Models\QcInspection;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use InvalidArgumentException;

/**
 * The slip quality hands over with the batch: PASSED or REJECTED, who
 * decided, when, and what was measured. 80 mm wide for the label printer at
 * the checkpoint; the store's own batch sticker follows once the batch is
 * put away.
 */
class QcSlipService
{
    public function render(QcInspection $inspection): PdfDocument
    {
        if ($inspection->isOpen()) {
            throw new InvalidArgumentException("Inspection {$inspection->number} has not been decided yet; there is nothing to print.");
        }

        $inspection->loadMissing(['lot.vendor', 'item.stockUom', 'decidedBy', 'destinationWarehouse']);

        $size = config('erp.labels.qc_slip', ['width' => 80, 'height' => 60]);
        $points = fn (float $mm): float => $mm * 72 / 25.4;

        return Pdf::loadView('pdf.qc-slip', [
            'inspection' => $inspection,
            'lot' => $inspection->lot,
            'item' => $inspection->item,
            'passed' => $inspection->status === LotQcStatus::Approved,
            'company' => config('erp.company.name'),
            'width' => $size['width'],
            'height' => $size['height'],
        ])->setPaper([0, 0, $points((float) $size['width']), $points((float) $size['height'])], 'portrait');
    }

    public function filename(QcInspection $inspection): string
    {
        return 'qc-slip-'.$inspection->number.'.pdf';
    }
}
