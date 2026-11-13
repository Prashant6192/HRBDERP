<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Enums;

/**
 * How a marketplace's label PDFs are read. Meesho and Flipkart print text
 * that can be read exactly. Myntra's labels and invoices arrive as
 * pictures: the uploader's own browser reads their words and barcodes,
 * and the Myntra parser makes parcels of them. Amazon sends each label as
 * a picture followed by its tax invoice as text: the browser reads the
 * label's barcode and words, the server reads the invoice exactly. Website
 * orders come as Shiprocket's labels, which print text too. The AI reader
 * is for a marketplace none of these fit.
 */
enum LabelReaderKind: string
{
    case Meesho = 'meesho';
    case Flipkart = 'flipkart';
    case Myntra = 'myntra';
    case Amazon = 'amazon';
    // Website orders: the shipping labels Shiprocket makes.
    case Shiprocket = 'shiprocket';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Meesho => 'Meesho label text',
            self::Flipkart => 'Flipkart label text',
            self::Myntra => 'Read on this computer',
            self::Amazon => 'Label read on this computer, invoice text',
            self::Shiprocket => 'Shiprocket labels and invoices',
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

    /**
     * Whether the uploader's browser reads the file's picture pages (their
     * words and barcodes) before the upload.
     */
    public function readsInBrowser(): bool
    {
        return in_array($this, [self::Myntra, self::Amazon, self::Shiprocket], true);
    }

    /**
     * Whether a tax invoice that came as its own file prints after the
     * label. Myntra's invoice is a 4×6 page; the website's is A4 and stays
     * off the label printer.
     */
    public function printsSeparateInvoice(): bool
    {
        return $this === self::Myntra;
    }
}
