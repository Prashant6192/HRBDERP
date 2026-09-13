<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Contracts;

use App\Domain\Procurement\DTOs\InvoiceExtraction;

/**
 * Reads a supplier's bill — a PDF or a photo — into structured particulars.
 * The production implementation asks Claude; tests bind a fake.
 */
interface InvoiceReader
{
    public function available(): bool;

    public function read(string $contents, string $mime, string $filename): InvoiceExtraction;
}
