<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Support\ChallanCode;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use InvalidArgumentException;

/**
 * The challan that travels with the consignment: what left, from where,
 * for where, on which vehicle — and the QR the destination scans to book
 * it in. The QR opens the transfer in the ERP with the inward code on the
 * end, so a phone camera lands the receiver on the right screen.
 */
class TransferChallanService
{
    public function render(StockTransfer $transfer): PdfDocument
    {
        if (! $transfer->status->isOnTheRoad() && ! in_array($transfer->status, [StockTransferStatus::Received, StockTransferStatus::Discrepancy], strict: true)) {
            throw new InvalidArgumentException("{$transfer->number} has not been dispatched; there is no challan to print yet.");
        }

        // Dispatched before inward codes existed: it gets one now.
        if ($transfer->challan_code === null) {
            $transfer->forceFill(['challan_code' => ChallanCode::generate()])->save();
        }

        $transfer->loadMissing([
            'lines.item.stockUom', 'lines.lot',
            'sourceFacility', 'sourceStore', 'destinationFacility', 'destinationStore',
            'dispatcher', 'approver', 'requester',
        ]);

        $url = route('transfers.show', ['transfer' => $transfer->id, 'scan' => $transfer->challan_code]);
        $png = (new Writer(new GDLibRenderer(260, 2)))->writeString($url);

        return Pdf::loadView('pdf.transfer-challan', [
            'transfer' => $transfer,
            'lines' => $transfer->lines->filter(fn ($l) => (float) $l->quantity_dispatched > 0)->values(),
            'code' => ChallanCode::format((string) $transfer->challan_code),
            'qr' => 'data:image/png;base64,'.base64_encode($png),
            'url' => $url,
            'company' => config('erp.company.name'),
        ])->setPaper('a4', 'portrait');
    }

    public function filename(StockTransfer $transfer): string
    {
        return 'challan-'.$transfer->number.'.pdf';
    }
}
