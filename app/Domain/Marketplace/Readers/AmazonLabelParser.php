<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Marketplace\Contracts\LabelFileParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * Amazon's easy-ship label PDF: for each parcel, the courier label (a
 * picture) and then its tax invoice (text) on the page after it.
 *
 *  - The label's AWB is its Code 128 barcode, read by the uploader's
 *    browser along with the label's words ("[barcode code_128] 3753…").
 *    The words give the courier ("ATSPL", or "ATSPL_ DELHIVERY" when
 *    Delhivery carries it), prepaid or COD, the order id and the name.
 *  - The invoice is read exactly from its text: the order number, invoice
 *    number and date, the seller's GSTIN, where it is delivered, and each
 *    product — the seller's SKU is in brackets after the ASIN,
 *    "B09NKZHJYY ( Medicated_Oil_300ml )".
 *
 * A label and the invoice pages after it make one parcel. A label the
 * browser did not read is still the page before its invoice: the parcel
 * keeps both pages and asks for the AWB.
 */
final class AmazonLabelParser implements LabelFileParser
{
    private const string LABEL = 'label';

    private const string INVOICE = 'invoice';

    private const string BLANK = 'blank';

    private const string COD = '/\bCOD\b|cash\s*on\s*delivery|pay\s*on\s*delivery/i';

    // Words but no AWB: a label the browser read poorly, or a page that is no label at all.
    private const string OTHER = 'other';

    /**
     * Whether a page is one of Amazon's tax invoices.
     */
    public static function isInvoice(string $text): bool
    {
        return preg_match('/amazon/i', $text) === 1
            && (preg_match('/tax\s*invoice\s*\/\s*bill\s*of\s*supply/i', $text) === 1
                || (preg_match('/order\s*number\s*:/i', $text) === 1 && preg_match('/invoice\s*number\s*:/i', $text) === 1));
    }

    public function parseFile(array $texts): array
    {
        ksort($texts);
        $kinds = array_map(fn (string $t) => $this->kind($t), $texts);

        /** @var list<array{label: int|null, invoices: list<int>}> $groups */
        $groups = [];
        $open = null;

        foreach ($kinds as $page => $kind) {
            if ($kind === self::LABEL) {
                if ($open !== null) {
                    $groups[] = $open;
                }

                $open = ['label' => $page, 'invoices' => []];

                continue;
            }

            if ($kind !== self::INVOICE) {
                if ($open !== null) {
                    $groups[] = $open;
                    $open = null;
                }

                continue;
            }

            if ($open !== null && $this->continues($open, $texts, $page)) {
                $open['invoices'][] = $page;

                continue;
            }

            if ($open !== null) {
                $groups[] = $open;
            }

            // An invoice with no label read before it: the picture page just
            // before it is its label, when nothing else has claimed it.
            $before = $page - 1;
            $open = ['label' => in_array($kinds[$before] ?? null, [self::BLANK, self::OTHER], true) ? $before : null, 'invoices' => [$page]];
        }

        if ($open !== null) {
            $groups[] = $open;
        }

        return array_map(fn (array $g) => $this->parcel($g['label'], $g['invoices'], $texts), $groups);
    }

    private function kind(string $text): string
    {
        if (self::isInvoice($text)) {
            return self::INVOICE;
        }

        [$lines, $codes] = $this->split($text);

        if ($this->awb($codes, $lines) !== null || preg_match('/ship\s*to\s*:|\bAWB\b/i', implode("\n", $lines)) === 1) {
            return self::LABEL;
        }

        return trim($text) === '' ? self::BLANK : self::OTHER;
    }

    /**
     * Whether an invoice page belongs to the parcel already open: its first
     * invoice, a page that carries on the one before ("Page 2 of 2"), or
     * another page for the same order.
     *
     * @param  array{label: int|null, invoices: list<int>}  $open
     * @param  array<int, string>  $texts
     */
    private function continues(array $open, array $texts, int $page): bool
    {
        if ($open['invoices'] === []) {
            return true;
        }

        $order = $this->orderNumber($texts[$page]);

        return $order === null || $order === $this->orderNumber($texts[$open['invoices'][0]]);
    }

    /**
     * @param  list<int>  $invoices
     * @param  array<int, string>  $texts
     */
    private function parcel(?int $label, array $invoices, array $texts): LabelExtraction
    {
        $warnings = [];
        [$lines, $codes] = $label === null ? [[], []] : $this->split($texts[$label]);
        $words = implode("\n", $lines);

        $awb = $this->awb($codes, $lines);
        $labelOrder = $this->orderNumber($words);
        $invoice = implode("\n", array_map(fn (int $p) => $texts[$p], $invoices));

        $order = $invoices === [] ? null : $this->orderNumber($invoice);
        $items = $invoices === [] ? [] : $this->items($invoice);

        if ($label === null) {
            $warnings[] = 'No Amazon shipping label was found before this invoice. Type the AWB from the parcel\'s label.';
        } elseif ($awb === null) {
            $warnings[] = 'The AWB on this Amazon label could not be read. Type it from the label, or scan it on the parcel screen.';
        }

        if ($invoices === []) {
            $warnings[] = 'No tax invoice followed this Amazon label, so the product is not known. Add the product before it is packed.';
        } elseif ($items === []) {
            $warnings[] = 'The product on this Amazon invoice could not be read. Add the product before it is packed.';
        } elseif (in_array(true, array_column($items, 'guessed'), true)) {
            $warnings[] = 'The quantity on this Amazon invoice could not be read clearly and was taken as 1. Check it before packing.';
        }

        // OCR slips on a digit or two; a different order differs in many.
        if ($order !== null && $labelOrder !== null && levenshtein($order, $labelOrder) > 3) {
            $warnings[] = "This label is for order {$labelOrder} but the invoice after it is for order {$order}. Check the pages belong together.";
        }

        [$name, $pincode] = $invoices === [] ? [null, null] : $this->shipTo($invoice);
        $payment = $this->payment($words, $invoices === [] ? null : $invoice);

        if ($payment !== null && $words !== '' && $this->labelSays($words) !== null && $this->labelSays($words) !== $payment) {
            $warnings[] = 'The label and the invoice disagree on whether this parcel is COD. Check the label before it is handed over.';
        }

        return LabelExtraction::fromArray([
            'pages' => array_values(array_filter([$label, ...$invoices], fn (?int $p) => $p !== null)),
            'awb' => $awb,
            'order_number' => $order ?? $labelOrder,
            'courier' => $this->courier($words),
            'payment_mode' => $payment,
            'payable_amount' => $invoices === [] ? null : $this->total($invoice),
            'invoice_number' => preg_match('/invoice\s*number\s*:\s*([A-Z0-9][A-Z0-9\/-]*)/i', $invoice, $m) === 1 ? $m[1] : null,
            'invoice_date' => preg_match('/invoice\s*date\s*:\s*(\d{1,2}[.\/-]\d{1,2}[.\/-]\d{4})/i', $invoice, $m) === 1 ? $m[1] : null,
            'seller_gstin' => preg_match('/GST\s*Registration\s*No\s*:\s*(\d{2}[A-Z0-9]{13})/i', $invoice, $m) === 1 ? $m[1] : null,
            // The invoice prints the name exactly; OCR adds stray marks.
            'customer_name' => $name ?? $this->labelName($lines),
            'customer_state' => $this->state($invoice),
            'customer_pincode' => $pincode,
            'lines' => array_map(fn (array $i) => ['seller_sku' => $i['sku'], 'description' => $i['description'], 'quantity' => $i['quantity']], $items),
            'warnings' => $warnings,
        ]);
    }

    /**
     * Amazon's order number, 3-7-7 digits, as printed or as OCR reads it
     * ("407 – 3807370 – 9110755").
     */
    private function orderNumber(string $text): ?string
    {
        return preg_match('/\b(\d{3})\s*[-–—]\s*(\d{7})\s*[-–—]\s*(\d{7})\b/u', $text, $m) === 1 ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /**
     * Each product row: its title, "| ASIN ( seller SKU )", the HSN line,
     * then the figures run together — "₹252.381₹252.385%IGST…" is a unit
     * price of 252.38, a quantity of 1 and a net amount of 252.38.
     *
     * @return list<array{sku: string, description: string, quantity: int, guessed: bool}>
     */
    private function items(string $invoice): array
    {
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $invoice);

        preg_match_all(
            '/(?:^|\n)\s*\d{1,3}\s*\n(?<desc>(?:(?!\n\s*\d{1,3}\s*\n).)+?)\|\s*(?<asin>[A-Z0-9]{10})\s*\(\s*(?<sku>[^()]+?)\s*\)\s*\n(?:\s*HSN\s*:?\s*\d*\s*\n)?(?<figures>[^\n]*)/su',
            $text,
            $rows,
            PREG_SET_ORDER,
        );

        $items = [];

        foreach ($rows as $row) {
            $quantity = null;

            if (preg_match('/^\s*₹?\s*([\d,]+\.\d{2})\s*(\d{1,4})\s*₹\s*([\d,]+\.\d{2})/u', $row['figures'], $f) === 1) {
                $unit = (float) str_replace(',', '', $f[1]);
                $net = (float) str_replace(',', '', $f[3]);
                $quantity = (int) $f[2];

                // A discount can make the net amount differ from price × qty;
                // a quantity that does not fit at all was misread.
                if ($quantity < 1 || ($unit > 0 && $net > $unit * $quantity + 0.05)) {
                    $quantity = null;
                }
            }

            $description = trim((string) preg_replace('/\s+/', ' ', $row['desc']), " |\n");

            $items[] = [
                'sku' => trim((string) preg_replace('/\s+/', ' ', $row['sku'])),
                'description' => $description,
                'quantity' => $quantity ?? 1,
                'guessed' => $quantity === null,
            ];
        }

        return $items;
    }

    private function total(string $invoice): ?string
    {
        if (preg_match('/invoice\s*value\s*:\s*([\d,]+(?:\.\d+)?)/i', $invoice, $m) === 1) {
            return str_replace(',', '', $m[1]);
        }

        if (preg_match('/^\s*TOTAL\s*:[^\n]*/mi', $invoice, $line) === 1 && preg_match_all('/₹\s*([\d,]+\.\d{2})/u', $line[0], $m) > 0) {
            return str_replace(',', '', (string) end($m[1]));
        }

        return null;
    }

    /**
     * The buyer's name and PIN code from the invoice's shipping address.
     * Amazon prints the buyer's name, then the recipient's, then the
     * address; when they are the same person the name appears twice.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function shipTo(string $invoice): array
    {
        $lines = TextLines::of($invoice);
        $at = TextLines::indexOf($lines, fn (string $l) => preg_match('/^shipping\s*address\s*:?/i', $l) === 1);

        if ($at === null) {
            return [null, null];
        }

        $end = TextLines::indexOf(array_slice($lines, $at + 1), fn (string $l) => preg_match('/state\s*\/\s*ut\s*code|place\s*of/i', $l) === 1);
        $block = array_slice($lines, $at + 1, $end ?? 8);

        $first = $block[0] ?? '';
        $second = $block[1] ?? '';
        $name = $second !== '' && preg_match('/[\d,#]/', $second) !== 1 ? $second : $first;
        $name = trim($name) === '' || preg_match('/\d/', $name) === 1 ? null : trim($name);

        $pincode = null;

        foreach (array_reverse($block) as $line) {
            if (preg_match('/\b(\d{6})\b/', $line, $m) === 1) {
                $pincode = $m[1];
                break;
            }
        }

        return [$name, $pincode];
    }

    private function state(string $invoice): ?string
    {
        if (preg_match('/place\s*of\s*delivery\s*:\s*([A-Za-z][A-Za-z &]*[A-Za-z])/i', $invoice, $m) !== 1
            && preg_match('/place\s*of\s*supply\s*:\s*([A-Za-z][A-Za-z &]*[A-Za-z])/i', $invoice, $m) !== 1) {
            return null;
        }

        return ucwords(strtolower(trim($m[1])));
    }

    /**
     * The name under "Ship To:" on the label, as OCR read it.
     *
     * @param  list<string>  $lines
     */
    private function labelName(array $lines): ?string
    {
        $at = TextLines::indexOf($lines, fn (string $l) => preg_match('/^ship\s*to\b/i', $l) === 1);

        if ($at === null || preg_match("/^([\\p{L}][\\p{L} .']{1,59})/u", $lines[$at + 1] ?? '', $m) !== 1) {
            return null;
        }

        $name = trim($m[1], " .'");

        return mb_strlen($name) >= 2 ? $name : null;
    }

    /**
     * Amazon Shipping prints "ATSPL"; a parcel Amazon hands to another
     * courier says so after it — "ATSPL_ DELHIVERY".
     */
    private function courier(string $words): ?string
    {
        if (preg_match('/ATSPL\s*[_-]\s*([A-Za-z][A-Za-z ]{2,30})/i', $words, $m) === 1 && ($other = Couriers::find($m[1])) !== null) {
            return $other;
        }

        return preg_match('/ATSPL|amazon\s*shipping/i', $words) === 1 ? 'Amazon Shipping' : Couriers::find($words);
    }

    /**
     * Prepaid or COD. The invoice is exact: a prepaid order's invoice
     * prints its "Mode of Payment" (GiftCard, UPI, AmazonPay…); a COD
     * order's prints none. Without the invoice, the label's own box says.
     */
    private function payment(string $words, ?string $invoice): ?string
    {
        if ($invoice !== null) {
            if (preg_match('/mode\s*of\s*payment\s*:\s*([^\n]*\S)/i', $invoice, $m) === 1) {
                return preg_match(self::COD, $m[1]) === 1 ? 'cod' : 'prepaid';
            }

            return preg_match('/payment\s*transaction\s*id/i', $invoice) === 1 ? 'prepaid' : 'cod';
        }

        return $this->labelSays($words);
    }

    /**
     * What the label's payment box says, as OCR read it.
     */
    private function labelSays(string $words): ?string
    {
        return match (true) {
            preg_match('/pre\s*-?\s*paid/i', $words) === 1 => 'prepaid',
            preg_match(self::COD, $words) === 1 => 'cod',
            default => null,
        };
    }

    /**
     * The AWB is the label's Code 128 barcode; the digits printed under it
     * ("AWB 375361526057", "AWB# 1337…") are the fallback.
     *
     * @param  list<array{format: string, value: string}>  $codes
     * @param  list<string>  $lines
     */
    private function awb(array $codes, array $lines): ?string
    {
        foreach ($codes as $code) {
            if (in_array($code['format'], ['code_128', 'code_39', 'code_93', 'itf'], true) && preg_match('/^[A-Z0-9]{8,30}$/i', $code['value']) === 1) {
                return strtoupper($code['value']);
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/\bAWB\s*#?\s*:?\s*([0-9 ]{10,24})/i', $line, $m) === 1) {
                $digits = str_replace(' ', '', $m[1]);

                if (strlen($digits) >= 10 && strlen($digits) <= 20) {
                    return $digits;
                }
            }
        }

        return null;
    }

    /**
     * The page's words, and its barcodes as the browser wrote them.
     *
     * @return array{0: list<string>, 1: list<array{format: string, value: string}>}
     */
    private function split(string $text): array
    {
        $lines = [];
        $codes = [];

        foreach (TextLines::of($text) as $line) {
            if (preg_match('/^\[barcode ([a-z0-9_]+)\]\s*(.+)$/i', $line, $m) === 1) {
                $codes[] = ['format' => strtolower($m[1]), 'value' => trim($m[2])];

                continue;
            }

            $lines[] = $line;
        }

        return [$lines, $codes];
    }
}
