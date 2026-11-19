<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Services;

use App\Domain\Marketplace\Models\BlinkitProduct;
use App\Support\Barcode\Code128;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as Document;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Blinkit's labels as a PDF at the size of the label stock:
 *
 *  - SKU labels, 50 × 25 mm, two side by side on each 100 × 25 mm row of
 *    the roll (an odd count leaves the last row's right half blank);
 *  - box labels, 4 × 6 inch, one to a page.
 *
 * Each carries the product's UPC as a Code 128 barcode.
 */
class BlinkitLabelService
{
    public const int MOST = 1000;

    public const string SKU = 'sku';

    public const string BOX = 'box';

    public function pdf(BlinkitProduct $product, string $type, ?CarbonImmutable $made, ?CarbonImmutable $expiry, int $copies): Document
    {
        if (! in_array($type, [self::SKU, self::BOX], true)) {
            throw new InvalidArgumentException('Choose an SKU label or a box label.');
        }

        if ($copies < 1 || $copies > self::MOST) {
            throw new InvalidArgumentException('Print between 1 and '.self::MOST.' labels at a time.');
        }

        if ($made !== null && $expiry !== null && $expiry->lessThanOrEqualTo($made)) {
            throw new InvalidArgumentException('The expiry date must be after the manufacturing date.');
        }

        $points = fn (float $mm): float => $mm * 72 / 25.4;
        $data = [
            'product' => $product,
            // Drawn as boxes in the PDF itself: sharp at any printer's resolution.
            'bars' => Code128::bars($product->upc, $type === self::SKU ? 45.0 : 85.6),
            'made' => $made === null ? '—' : strtoupper($made->format('M Y')),
            'expiry' => $expiry === null ? '—' : strtoupper($expiry->format('M Y')),
            'mrp' => rtrim(rtrim(number_format((float) $product->mrp, 2, '.', ''), '0'), '.'),
            'copies' => $copies,
        ];

        return $type === self::SKU
            ? Pdf::loadView('pdf.blinkit-sku-labels', $data + ['rows' => (int) ceil($copies / 2)])->setPaper([0, 0, $points(100), $points(25)], 'portrait')
            : Pdf::loadView('pdf.blinkit-box-labels', $data)->setPaper([0, 0, 288, 432], 'portrait');
    }

    public function filename(BlinkitProduct $product, string $type): string
    {
        return sprintf('blinkit-%s-%s.pdf', $type === self::SKU ? 'sku' : 'box', preg_replace('/[^A-Za-z0-9]+/', '', $product->upc));
    }
}
