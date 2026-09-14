<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\DTOs\InvoiceExtraction;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;

/**
 * The bill reader the ERP actually uses.
 *
 * A PDF printed from billing software is read by the built-in reader,
 * on this server, with no key and no network. A photo or a scanned bill
 * carries no text, so it goes to Claude when a key is set; without one
 * the person is told to ask the supplier for the PDF or key the receipt
 * in. When the built-in reader finds nothing on a PDF and Claude is
 * available, Claude has a go.
 */
class BillReader implements InvoiceReader
{
    public function __construct(
        private readonly LocalInvoiceReader $local,
        private readonly ClaudeInvoiceReader $claude,
    ) {}

    public function available(): bool
    {
        return true;
    }

    /**
     * Whether photos and scans can be read too.
     */
    public function readsPhotos(): bool
    {
        return $this->claude->available();
    }

    public function read(string $contents, string $mime, string $filename): InvoiceExtraction
    {
        if ($mime !== 'application/pdf') {
            if ($this->claude->available()) {
                return $this->claude->read($contents, $mime, $filename);
            }

            throw new InvoiceIntakeException('A photo of a bill needs the Claude bill reader, which is not set up on this server (no ANTHROPIC_API_KEY). Upload the PDF the supplier\'s billing software produced, or enter the receipt by hand.');
        }

        try {
            $extraction = $this->local->read($contents, $mime, $filename);
        } catch (InvoiceIntakeException $e) {
            if ($this->claude->available()) {
                return $this->claude->read($contents, $mime, $filename);
            }

            throw $e;
        }

        if ($extraction->lines === [] && $this->claude->available()) {
            return $this->claude->read($contents, $mime, $filename);
        }

        return $extraction;
    }
}
