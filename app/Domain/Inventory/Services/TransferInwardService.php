<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\ChallanReader;
use App\Domain\Inventory\DTOs\ChallanExtraction;
use App\Domain\Inventory\Exceptions\StockTransferException;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Support\ChallanCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The gate at the destination. A consignment is booked in only once the
 * paperwork that came with it has been matched to the transfer: the inward
 * code off the challan's QR, typed or scanned, or the transport document
 * read for the transfer's number. Until then nothing reaches the store.
 */
class TransferInwardService
{
    public const string DISK = 'local';

    public function __construct(private readonly ChallanReader $reader) {}

    /**
     * @param  string|null  $code  what the scanner or the person entered: the QR's contents, the code, or the number and the code
     * @param  UploadedFile|null  $document  the transporter's invoice, LR or the challan itself
     */
    public function scan(StockTransfer $transfer, int $userId, ?string $code, ?UploadedFile $document = null, ?string $reference = null, ?string $transporter = null): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId, $code, $document, $reference, $transporter): StockTransfer {
            $transfer = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->getKey());

            if (! $transfer->status->canBeReceived()) {
                throw new StockTransferException("{$transfer->number} is {$transfer->status->label()}; there is nothing on the road to book in.");
            }

            if ($transfer->challan_code === null) {
                $transfer->forceFill(['challan_code' => ChallanCode::generate()])->save();
            }

            $matchedBy = null;
            $typed = ChallanCode::parse($code);

            if ($typed['code'] !== null) {
                // The QR names its own transfer; a challan for another one
                // is a different consignment, whatever its code.
                $other = $typed['number'] !== null && $typed['number'] !== strtoupper($transfer->number)
                    ? $typed['number']
                    : ($typed['id'] !== null && $typed['id'] !== $transfer->id
                        ? (StockTransfer::query()->find($typed['id'])?->number ?? "transfer #{$typed['id']}")
                        : null);

                if ($other !== null) {
                    throw new StockTransferException("That challan is for {$other}, not {$transfer->number}. Open that transfer, or check the consignment.");
                }

                if (! hash_equals((string) $transfer->challan_code, $typed['code'])) {
                    throw new StockTransferException("That inward code does not belong to {$transfer->number}. Scan the QR on the challan that came with this consignment, or type the code printed under it.");
                }

                $matchedBy = 'code';
            } elseif (trim((string) $code) !== '') {
                throw new StockTransferException('That is not an inward code. Scan the QR on the challan, or type the eight-character code printed under it (like ABCD-2345).');
            }

            $stored = null;
            $extraction = null;

            if ($document !== null) {
                $stored = $this->keep($transfer, $document);

                if ($matchedBy === null) {
                    // Only the document to go on: it has to name this transfer.
                    try {
                        $extraction = $this->reader->read($stored['contents'], $stored['mime'], $stored['name']);
                    } catch (StockTransferException $e) {
                        Storage::disk(self::DISK)->delete($stored['path']);

                        throw $e;
                    }

                    $namesCode = $extraction->challanCode !== null && hash_equals((string) $transfer->challan_code, $extraction->challanCode);
                    $namesNumber = $extraction->transferNumber !== null && $extraction->transferNumber === strtoupper($transfer->number);

                    if (! $namesCode && ! $namesNumber) {
                        Storage::disk(self::DISK)->delete($stored['path']);

                        throw new StockTransferException(
                            "The document does not mention {$transfer->number}".($extraction->transferNumber !== null ? " (it names {$extraction->transferNumber})" : '')
                            .'. Check it is the paperwork for this consignment, or type the inward code printed under the QR on the challan.'
                        );
                    }

                    $matchedBy = 'document';
                } elseif ($this->reader->available()) {
                    // The code already proved the consignment; the reading
                    // only fills in the transporter's particulars.
                    try {
                        $extraction = $this->reader->read($stored['contents'], $stored['mime'], $stored['name']);
                    } catch (StockTransferException) {
                        $extraction = null;
                    }
                }
            }

            if ($matchedBy === null) {
                throw new StockTransferException('Scan the QR on the transfer challan, type the inward code printed under it, or upload the document that came with the consignment.');
            }

            $transfer->forceFill([
                'scanned_at' => now(),
                'scanned_by' => $userId,
                'transporter' => $this->first($transporter, $extraction?->transporter, $transfer->transporter),
                'transport_reference' => $this->first($reference, $extraction?->lrNumber, $transfer->transport_reference),
                'vehicle_ref' => $this->first($transfer->vehicle_ref, $extraction?->vehicle),
                'transport_document_path' => $stored['path'] ?? $transfer->transport_document_path,
                'transport_document_name' => $stored['name'] ?? $transfer->transport_document_name,
                'transport_document_mime' => $stored['mime'] ?? $transfer->transport_document_mime,
                'transport_extraction' => $extraction instanceof ChallanExtraction
                    ? [...$extraction->toArray(), 'matched_by' => $matchedBy]
                    : ($transfer->transport_extraction ?? ['matched_by' => $matchedBy]),
            ])->save();

            return $transfer->refresh();
        });
    }

    /**
     * @return array{path: string, name: string, mime: string, contents: string}
     */
    private function keep(StockTransfer $transfer, UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();

        if (! in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], strict: true)) {
            throw new StockTransferException('Upload the document as a PDF or a JPEG / PNG / WebP photo.');
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $path = 'stock-transfers/inward/'.now()->format('Y/m').'/'.$transfer->id.'-'.Str::uuid().'.'.($file->guessExtension() ?: 'bin');
        Storage::disk(self::DISK)->put($path, $contents);

        return ['path' => $path, 'name' => (string) $file->getClientOriginalName(), 'mime' => $mime, 'contents' => $contents];
    }

    private function first(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
