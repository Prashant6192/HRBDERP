<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Contracts;

use App\Domain\Inventory\DTOs\ChallanExtraction;
use App\Domain\Inventory\Exceptions\StockTransferException;

/**
 * Reads the document that came with a consignment — the transfer challan,
 * a transporter's LR or an invoice for the move — for the transfer it
 * belongs to. The production implementation asks Claude; tests bind a fake.
 */
interface ChallanReader
{
    public function available(): bool;

    /**
     * @throws StockTransferException when the document cannot be read
     */
    public function read(string $contents, string $mime, string $filename): ChallanExtraction;
}
