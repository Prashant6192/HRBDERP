<?php

declare(strict_types=1);

namespace App\Domain\Planning\Services;

use App\Domain\Planning\Models\MaterialRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;

/**
 * The printed PMR the store picks against and purchase orders from.
 */
class MaterialRequestPdfService
{
    public function render(MaterialRequest $request): PdfDocument
    {
        $request->load(['plan.formula', 'plan.product', 'plan.plannedUom', 'warehouse', 'requestedBy', 'lines.item', 'lines.uom']);

        return Pdf::loadView('pdf.material-request', [
            'request' => $request,
            'company' => config('erp.company.name'),
            'printedAt' => now(),
        ])->setPaper('a4', 'portrait');
    }

    public function filename(MaterialRequest $request): string
    {
        return "{$request->number}.pdf";
    }
}
