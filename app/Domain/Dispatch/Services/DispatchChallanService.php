<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Services;

use App\Domain\Dispatch\Enums\DispatchStatus;
use App\Domain\Dispatch\Models\Dispatch;
use App\Domain\Dispatch\Support\GstStateCodes;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use InvalidArgumentException;

/**
 * The delivery challan and packing list that travels with a consignment:
 * who sent it, who receives it, the invoice it is against, and what is in
 * the boxes batch by batch. The tax invoice itself comes from the billing
 * software; this is the factory's paper.
 */
class DispatchChallanService
{
    public function __construct(private readonly EInvoiceService $einvoice) {}

    public function render(Dispatch $dispatch): PdfDocument
    {
        if (in_array($dispatch->status, [DispatchStatus::Draft, DispatchStatus::Cancelled], strict: true)) {
            throw new InvalidArgumentException("{$dispatch->number} has no invoice recorded yet; there is nothing to print.");
        }

        $dispatch->loadMissing(['facility', 'warehouse', 'customer', 'lines.item.stockUom', 'lines.lot', 'dispatcher', 'creator']);

        return Pdf::loadView('pdf.dispatch-challan', [
            'dispatch' => $dispatch,
            'seller' => $this->einvoice->seller($dispatch->facility),
            'stateName' => GstStateCodes::name($dispatch->place_of_supply),
            'company' => config('erp.company.name'),
        ])->setPaper('a4', 'portrait');
    }

    public function filename(Dispatch $dispatch): string
    {
        return 'dispatch-'.$dispatch->number.'.pdf';
    }
}
