<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Marketplace\Contracts\AiLabelReader;
use App\Domain\Marketplace\DTOs\LabelExtraction;
use App\Domain\Marketplace\DTOs\LabelReading;
use App\Domain\Marketplace\Exceptions\OnlineOrderException;
use App\Support\Ai\ClaudeDocuments;
use App\Support\Ai\DocumentReadException;

/**
 * Asks Claude to read a marketplace's label PDF into parcels — for labels
 * that arrive as pictures (Amazon, Myntra) and pages the text readers
 * could not place.
 */
class ClaudeLabelReader implements AiLabelReader
{
    private const SYSTEM = <<<'PROMPT'
You read the shipping-label PDFs that Indian online marketplaces (Amazon, Flipkart, Meesho, Myntra and others) generate for a seller of ayurvedic cosmetics. Each parcel has a courier label, and some marketplaces add the parcel's tax invoice on the page or pages right after its label. Return every parcel in the file.

Rules:
- Read only what is printed. Never invent a value; use null when it is not printed.
- pages: the 1-based page numbers that belong to one parcel, in order: its label page plus any invoice pages that follow it for the same order. A page belongs to exactly one parcel, or to ignored_pages when it is not part of any parcel (a pick list, a manifest, a blank page).
- awb: the courier's tracking / AWB number, usually printed under or beside the main barcode ("AWB 1234…", "AWB No."). Digits and letters only, no spaces or dashes.
- alt_code: any second tracking code printed under another barcode on the label when it differs from the AWB. Otherwise null.
- order_number: the marketplace order id as printed (e.g. "Order Id: 402-1234567-1234567", "OD…"). Keep dashes as printed.
- courier: the courier company carrying the parcel as printed (Amazon Shipping / ATSPL, Ekart, Delhivery, Shadowfax, Xpress Bees, Valmo, Ecom Express…). A route code such as EK_E2E means Ekart.
- payment_mode: "cod" when the label says COD, cash on delivery or an amount to collect; "prepaid" when it says prepaid or do not collect cash; otherwise null.
- payable_amount: the amount to collect for COD, else the invoice total, as a plain number.
- invoice_number, invoice_date (YYYY-MM-DD; Indian documents print DD.MM.YYYY or DD-MM-YYYY), seller_gstin (the seller's 15-character GSTIN), customer_name, customer_state (the delivery state).
- lines: one per product in the parcel, from the label's product table or the invoice. seller_sku is the seller's own SKU: on Amazon invoices it is the text in parentheses after the ASIN, e.g. "B0XXXXXXXX ( Hair_Oil_500ml )" gives "Hair_Oil_500ml"; on other labels it is the "SKU" column. description is the product title as printed. quantity is the number of units ordered. Skip shipping charges, handling fees, COD fees and totals. If the label names no product at all, return an empty list.
- warnings: anything unclear, cut off or unreadable on a parcel's pages.
PROMPT;

    private const LINE = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['seller_sku', 'description', 'quantity'],
        'properties' => [
            'seller_sku' => ['type' => 'string'],
            'description' => ['type' => ['string', 'null']],
            'quantity' => ['type' => 'integer'],
        ],
    ];

    private const PARCEL_FIELDS = ['awb', 'alt_code', 'order_number', 'courier', 'payment_mode', 'payable_amount', 'invoice_number', 'invoice_date', 'seller_gstin', 'customer_name', 'customer_state'];

    public function __construct(private readonly ClaudeDocuments $documents) {}

    public function available(): bool
    {
        return $this->documents->available();
    }

    public function read(string $contents, string $filename, string $marketplace, int $pageCount): LabelReading
    {
        try {
            $data = $this->documents->extract(
                self::SYSTEM,
                $this->schema(),
                $contents,
                'application/pdf',
                "These are {$marketplace} shipping labels ({$filename}, {$pageCount} pages). Return every parcel in the file.",
            );
        } catch (DocumentReadException $e) {
            throw new OnlineOrderException(match ($e->reason) {
                DocumentReadException::NOT_CONFIGURED => 'The AI label reader is not set up on this server (ANTHROPIC_API_KEY is missing), and these labels carry no text to read.',
                DocumentReadException::API => 'The labels could not be read right now ('.$e->getMessage().'). Try the upload again in a moment.',
                DocumentReadException::REFUSED => 'The reader declined this file. Check it is the marketplace\'s label PDF.',
                default => 'The reader could not make out this file. Check it is the marketplace\'s label PDF.',
            }, previous: $e);
        }

        $parcels = array_map(
            fn (array $p) => LabelExtraction::fromArray($p),
            array_values(array_filter((array) ($data['parcels'] ?? []), 'is_array')),
        );

        return new LabelReading(
            parcels: array_values(array_filter($parcels, fn (LabelExtraction $p) => $p->pages !== [])),
            readWith: 'ai',
            model: $this->documents->model(),
            ignoredPages: array_values(array_map('intval', (array) ($data['ignored_pages'] ?? []))),
            warnings: array_values(array_filter((array) ($data['warnings'] ?? []), 'is_string')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $parcel = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_merge(['pages'], self::PARCEL_FIELDS, ['lines', 'warnings']),
            'properties' => array_merge(
                ['pages' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                array_fill_keys(self::PARCEL_FIELDS, ['type' => ['string', 'null']]),
                [
                    'lines' => ['type' => 'array', 'items' => self::LINE],
                    'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ),
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['parcels', 'ignored_pages', 'warnings'],
            'properties' => [
                'parcels' => ['type' => 'array', 'items' => $parcel],
                'ignored_pages' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
