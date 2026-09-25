<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\HandoverSheet;
use App\Domain\Marketplace\Support\Cutoff;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * The sheet the courier's person counts the parcels against and signs.
 */
class HandoverSheetPdf
{
    public function render(HandoverSheet $sheet): PdfDocument
    {
        $sheet->loadMissing(['shipments.marketplace:id,name', 'facility:id,name', 'warehouse:id,name', 'handedOverBy:id,name']);

        return Pdf::loadView('pdf.handover-sheet', [
            'sheet' => $sheet,
            'company' => (string) (config('erp.company.legal_name') ?: config('erp.company.name')),
            'at' => $sheet->handed_over_at->timezone(Cutoff::timezone())->format('j M Y, g:i A'),
        ])->setPaper('a4', 'portrait');
    }

    public function filename(HandoverSheet $sheet): string
    {
        return 'handover-'.$sheet->number.'.pdf';
    }
}
