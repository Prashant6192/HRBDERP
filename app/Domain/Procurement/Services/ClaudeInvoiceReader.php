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
- The vendor is the SELLER / supplier who issued the bill: the letterhead at the top, with its own PAN, CIN, "GSTIN" or "GSTIN/UIN". The blocks headed "Buyer", "Buyer (Bill to)", "Buyer (if other than consignee)", "Consignee", "Consignee (Ship to)" or "Ship to" are the BUYER. Put the seller's 15-character GSTIN in vendor_gstin and the buyer's in buyer_gstin; never swap them.
- document_type: "tax_invoice" for a tax invoice or e-invoice (an IRN / Ack No. block means e-invoice), "proforma" for a proforma invoice, "delivery_challan", "quotation", or "other".
- invoice_number is the bill's own number ("Invoice No.", "Invoice #", or the SO / proforma number printed in the title). invoice_date is the "Invoice Date" / "Dated" beside it — not the Ack Date, the buyer's PO date or the delivery note date.
- One line per goods line on the bill, in the order printed. Skip freight, packing, discount, round-off, tax and total lines; put them in warnings if they are material.
- quantity is the QUANTITY column in the unit shown (a pack size such as "2 BAG X 25 KG" describes the packing; the quantity is 50 KG). rate and amount are plain numbers without currency symbols or thousands separators; a stray glyph before a number is the rupee sign. unit is the unit as printed (KG, kg., LTR, PCS, NOS, BOX…).
- Dates as YYYY-MM-DD. Indian bills print DD/MM/YYYY, DD-MM-YYYY, D/M/YYYY or DD-Mon-YY (28-Feb-26 is 2026-02-28).
- batch comes from the line itself: Tally-style bills print "Batch : XX-1234/25-26" on the line below the description; a batch column or a batch table tied to the line also counts. manufactured_at and expiry_at likewise, only when tied to the line.
- If the document is not a supplier bill, or is unreadable, say so in warnings and return no lines.
PROMPT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['document_type', 'vendor_name', 'vendor_gstin', 'buyer_gstin', 'vendor_address', 'vendor_phone', 'vendor_email', 'invoice_number', 'invoice_date', 'subtotal', 'tax', 'total', 'currency', 'lines', 'warnings'],
        'properties' => [
            'document_type' => ['type' => ['string', 'null'], 'enum' => ['tax_invoice', 'proforma', 'delivery_challan', 'quotation', 'other', null]],
            'vendor_name' => ['type' => ['string', 'null']],
            'vendor_gstin' => ['type' => ['string', 'null']],
            'buyer_gstin' => ['type' => ['string', 'null']],
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
