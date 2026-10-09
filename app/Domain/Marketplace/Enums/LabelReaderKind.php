<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * How a marketplace's label PDFs are read. Meesho and Flipkart print text
 * that can be read exactly. Myntra's labels and invoices arrive as
 * pictures: the uploader's own browser reads their words and barcodes,
 * and the Myntra parser makes parcels of them. Amazon's pictures go to
 * the AI reader.
 */
enum LabelReaderKind: string
{
    case Meesho = 'meesho';
    case Flipkart = 'flipkart';
    case Myntra = 'myntra';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Meesho => 'Meesho label text',
            self::Flipkart => 'Flipkart label text',
            self::Myntra => 'Read on this computer',
            self::Ai => 'AI reader',
        };
    }

    /**
     * Whether a page carries the courier label above its tax invoice, so a
     * 4×6 label printer is given the label alone (Meesho and Flipkart).
     */
    public function labelAboveInvoice(): bool
    {
        return $this === self::Meesho || $this === self::Flipkart;
    }
}
