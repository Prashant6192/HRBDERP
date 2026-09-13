<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Contracts\ChallanReader;
use App\Domain\Inventory\DTOs\ChallanExtraction;
use App\Domain\Inventory\Exceptions\StockTransferException;
use App\Support\Ai\ClaudeDocuments;
use App\Support\Ai\DocumentReadException;

/**
 * Asks Claude which transfer a consignment's paperwork belongs to.
 */
class ClaudeChallanReader implements ChallanReader
{
    private const SYSTEM = <<<'PROMPT'
You read the paperwork that travels with a consignment between two sites of a cosmetics manufacturer in India: the company's own STOCK TRANSFER CHALLAN printed by its ERP, a transporter's lorry receipt (LR / consignment note / docket), or a tax invoice or delivery challan for the movement. Return the particulars as structured data.

Rules:
- Read only what is printed. Never invent a value; leave a field null when the document does not state it.
- transfer_number is the ERP's transfer reference, printed as TRF-yymm-nnnnn (for example TRF-2609-00012). It may appear as "Transfer", "STN", "Ref" or inside a QR caption. Null if no such reference is printed.
- challan_code is the eight-character inward code printed next to or under the QR code on the company's challan, letters and digits, often written as ABCD-2345. Null if absent.
- lr_number is the transporter's LR / GR / consignment / docket number. transporter is the transport company's name. vehicle is the vehicle registration.
- date as YYYY-MM-DD. Indian documents usually print DD/MM/YYYY or DD-MM-YYYY.
- lines: one per goods line in the order printed, with the quantity as a plain number and the batch number when printed. Skip freight, charges and totals.
- If the document is none of these, or is unreadable, say so in warnings and leave the references null.
PROMPT;

    private const SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['transfer_number', 'challan_code', 'transporter', 'lr_number', 'vehicle', 'date', 'lines', 'warnings'],
        'properties' => [
            'transfer_number' => ['type' => ['string', 'null']],
            'challan_code' => ['type' => ['string', 'null']],
            'transporter' => ['type' => ['string', 'null']],
            'lr_number' => ['type' => ['string', 'null']],
            'vehicle' => ['type' => ['string', 'null']],
            'date' => ['type' => ['string', 'null']],
            'lines' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['description', 'quantity', 'batch'],
                    'properties' => [
                        'description' => ['type' => 'string'],
                        'quantity' => ['type' => ['string', 'null']],
                        'batch' => ['type' => ['string', 'null']],
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

    public function read(string $contents, string $mime, string $filename): ChallanExtraction
    {
        try {
            $data = $this->documents->extract(self::SYSTEM, self::SCHEMA, $contents, $mime, "Read this consignment document ({$filename}) and return its particulars.");
        } catch (DocumentReadException $e) {
            throw new StockTransferException(match ($e->reason) {
                DocumentReadException::NOT_CONFIGURED => 'The document reader is not set up on this server (ANTHROPIC_API_KEY is missing). Type the inward code printed under the QR on the challan instead.',
                DocumentReadException::API => 'The document could not be read right now ('.$e->getMessage().'). Try again in a moment, or type the inward code printed under the QR.',
                DocumentReadException::REFUSED => 'The document was declined by the reader. Type the inward code printed under the QR on the challan.',
                default => 'The reader could not make out this document. Type the inward code printed under the QR on the challan.',
            }, previous: $e);
        }

        return ChallanExtraction::fromArray($data, $this->documents->model());
    }
}
