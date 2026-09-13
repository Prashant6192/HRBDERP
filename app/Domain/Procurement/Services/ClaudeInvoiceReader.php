<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\DTOs\InvoiceExtraction;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;
use App\Support\Ai\ClaudeDocuments;
use App\Support\Ai\DocumentReadException;

/**
 * Asks Claude to read the bill. The document goes in as a PDF or image
 * block; the answer comes back as JSON fixed to a schema, so a stray word
 * in the reply cannot break the receipt.
 */
class ClaudeInvoiceReader implements InvoiceReader
{
    private const SYSTEM = <<<'PROMPT'
You read supplier invoices, delivery challans and purchase bills for a cosmetics manufacturer in India and return their particulars as structured data.

Rules:
- Read only what is printed. Never invent a value; leave a field null when the document does not state it.
- The vendor is the SELLER / supplier who issued the bill, not the buyer ("Bill to" / "Ship to" is the buyer). Take the seller's GSTIN (15 characters) when printed.
- One line per goods line on the bill, in the order printed. Skip freight, packing, discount, round-off and tax lines; put them in warnings if they are material.
- quantity, rate and amount are plain numbers without currency symbols or thousands separators. unit is the unit as printed (KG, LTR, PCS, NOS, BOX…).
- Dates as YYYY-MM-DD. Indian bills usually print DD/MM/YYYY or DD-MM-YYYY.
- batch, manufactured_at and expiry_at come only from the line itself or a batch table clearly tied to that line.
- If the document is not a supplier bill, or is unreadable, say so in warnings and return no lines.
PROMPT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['vendor_name', 'vendor_gstin', 'vendor_address', 'vendor_phone', 'vendor_email', 'invoice_number', 'invoice_date', 'subtotal', 'tax', 'total', 'currency', 'lines', 'warnings'],
        'properties' => [
            'vendor_name' => ['type' => ['string', 'null']],
            'vendor_gstin' => ['type' => ['string', 'null']],
            'vendor_address' => ['type' => ['string', 'null']],
            'vendor_phone' => ['type' => ['string', 'null']],
            'vendor_email' => ['type' => ['string', 'null']],
            'invoice_number' => ['type' => ['string', 'null']],
            'invoice_date' => ['type' => ['string', 'null']],
            'subtotal' => ['type' => ['string', 'null']],
            'tax' => ['type' => ['string', 'null']],
            'total' => ['type' => ['string', 'null']],
            'currency' => ['type' => 'string'],
            'lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['description', 'hsn', 'quantity', 'unit', 'rate', 'amount', 'batch', 'manufactured_at', 'expiry_at'],
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'hsn' => ['type' => ['string', 'null']],
                        'quantity' => ['type' => ['string', 'null']],
                        'unit' => ['type' => ['string', 'null']],
                        'rate' => ['type' => ['string', 'null']],
                        'amount' => ['type' => ['string', 'null']],
                        'batch' => ['type' => ['string', 'null']],
                        'manufactured_at' => ['type' => ['string', 'null']],
                        'expiry_at' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ],
    ];

    public function __construct(private readonly ClaudeDocuments $documents) {}

    public function available(): bool
    {
        return $this->documents->available();
    }

    public function read(string $contents, string $mime, string $filename): InvoiceExtraction
    {
        try {
            $data = $this->documents->extract(self::SYSTEM, self::SCHEMA, $contents, $mime, "Read this bill ({$filename}) and return its particulars.");
        } catch (DocumentReadException $e) {
            throw new InvoiceIntakeException(match ($e->reason) {
                DocumentReadException::NOT_CONFIGURED => 'Bill reading is not set up on this server: ANTHROPIC_API_KEY is missing. Enter the receipt by hand, or ask the administrator to add the key.',
                DocumentReadException::API => 'The bill could not be read right now ('.$e->getMessage().'). Try again in a moment, or enter the receipt by hand.',
                DocumentReadException::REFUSED => 'The document was declined by the reader. Enter the receipt by hand.',
                default => 'The reader returned nothing usable for this document. Enter the receipt by hand.',
            }, previous: $e);
        }

        return InvoiceExtraction::fromArray($data, $this->documents->model());
    }
}
