<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Marketplace\Contracts\LabelTextParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Myntra sends a day's courier labels and its tax invoices as two PDFs of
 * pictures. The uploader's browser reads each page's words (OCR) and its
 * barcodes, written one per line as "[barcode code_128] 2191…"; this
 * parser makes a parcel half of each page:
 *
 *  - a courier label: the AWB from the large barcode, the buyer's name
 *    and PIN code, prepaid or COD — no product;
 *  - a tax invoice: the PacketID, the order number, the product and its
 *    quantity, the buyer's name and PIN code. The invoice's QR code gives
 *    the invoice number, date, total and GSTIN exactly.
 *
 * The two halves are paired into one parcel by the buyer's name and PIN
 * code once both files are in.
 */
final class MyntraLabelParser implements LabelTextParser
{
    public function parse(string $text, int $page): ?LabelExtraction
    {
        [$lines, $codes] = $this->split($text);
        $all = implode("\n", $lines);

        return match (true) {
            preg_match('/tax\s*invoice|packet\s*id/i', $all) === 1 || $this->qr($codes) !== null => $this->invoice($lines, $codes, $page),
            preg_match('/buyer.{0,3}s\s*name|if\s*undelivered/i', $all) === 1 => $this->label($lines, $codes, $page),
            default => null,
        };
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array{format: string, value: string}>  $codes
     */
    private function label(array $lines, array $codes, int $page): ?LabelExtraction
    {
        $all = implode("\n", $lines);
        $awb = $this->awb($codes, $lines);

        $at = TextLines::indexOf($lines, fn (string $l) => preg_match('/buyer.{0,3}s\s*name/i', $l) === 1);
        $name = $at === null ? null : $this->name($lines[$at + 1] ?? '');

        // The buyer's declaration repeats the name: "I, Ravi Verma, declare…".
        if ($name === null && preg_match('/\b[I1l|]\s*,\s*([^,\n]{2,60}?)\s*,\s*declare/i', $all, $m) === 1) {
            $name = $this->name($m[1]);
        }

        // The route line prints the PIN code in brackets: "(845401)-(0910)".
        $pincode = preg_match('/\((\d{6})\)/', $all, $m) === 1 ? $m[1] : null;

        if ($pincode === null && $at !== null) {
            $end = TextLines::indexOf($lines, fn (string $l) => preg_match('/if\s*undelivered|return\s*to/i', $l) === 1) ?? count($lines);
            $pincode = preg_match('/\b(\d{6})\b/', implode(' ', array_slice($lines, $at + 1, max(0, $end - $at - 1))), $m) === 1 ? $m[1] : null;
        }

        $cod = preg_match('/\bCOD\b|cash\s*on\s*delivery|collect/i', $all) === 1;
        $prepaid = preg_match('/pre\s*-?\s*paid/i', $all) === 1;
        $amount = $cod && preg_match('/Rs\.?\s*([\d,]+(?:\.\d+)?)/i', $all, $m) === 1 ? str_replace(',', '', $m[1]) : null;

        $top = $at === null ? $all : implode("\n", array_slice($lines, 0, $at));

        return LabelExtraction::fromArray([
            'pages' => [$page],
            'awb' => $awb,
            // Myntra prints no courier name: its route code says who carries it.
            'courier' => Couriers::find($top) ?? Couriers::fromRouteCode($all) ?? Couriers::fromAwb($awb),
            'payment_mode' => $cod ? 'cod' : ($prepaid ? 'prepaid' : null),
            'payable_amount' => $amount,
            'customer_name' => $name,
            'customer_pincode' => $pincode,
            'lines' => [],
        ]);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<array{format: string, value: string}>  $codes
     */
    private function invoice(array $lines, array $codes, int $page): LabelExtraction
    {
        $all = implode("\n", $lines);
        $qr = $this->qr($codes) ?? [];

        $packet = preg_match('/packet\s*id\s*[:.]?\s*(\d{8,20})/i', $all, $m) === 1 ? $m[1] : null;

        foreach ($packet === null ? $codes : [] as $code) {
            if ($code['format'] !== 'qr_code' && preg_match('/^\d{8,20}$/', $code['value']) === 1) {
                $packet = $code['value'];
                break;
            }
        }

        $order = $this->orderFromQr($qr)
            ?? (preg_match('/order\s*number\s*[:.]?\s*(\d[\d\s-]{8,}\d)/i', $all, $m) === 1 ? preg_replace('/\s+/', '', $m[1]) : null);

        $invoiceNumber = $qr['invoiceNo'] ?? (preg_match('/invoice\s*number\s*[:.]?\s*([A-Z0-9\/-]{4,})/i', $all, $m) === 1 ? $m[1] : null);
        $invoiceDate = isset($qr['invoiceDate']) ? substr((string) $qr['invoiceDate'], 0, 10) : $this->printedDate($all);
        $total = $qr['am'] ?? $this->total($lines);
        $gstin = $qr['gstIn'] ?? (preg_match('/GSTIN\s*(?:Number)?\s*[:.]?\s*(\d{2}[A-Z0-9]{13})\b/i', $all, $m) === 1 ? $m[1] : null);

        $billTo = TextLines::indexOf($lines, fn (string $l) => preg_match('/bill\s*to|ship\s*to/i', $l) === 1);
        $name = null;
        $pincode = null;

        if ($billTo !== null) {
            $sameLine = trim((string) preg_replace('/^.*ship\s*to\s*:?/i', '', $lines[$billTo]));
            $name = $this->name($sameLine !== '' && preg_match('/bill\s*to/i', $sameLine) !== 1 ? $sameLine : ($lines[$billTo + 1] ?? ''));
            $end = TextLines::indexOf(array_slice($lines, $billTo + 1), fn (string $l) => preg_match('/bill\s*from|ship\s*from|gstin/i', $l) === 1);
            $address = array_slice($lines, $billTo + 1, $end ?? 6);
            $pincode = preg_match('/\b(\d{6})\b/', implode(' ', $address), $m) === 1 ? $m[1] : null;
        }

        $state = preg_match('/place\s*of\s*supply\s*[:.]?\s*([A-Za-z][A-Za-z &]+?)(?:\s{2,}|\s+nature|$)/im', $all, $m) === 1 ? trim($m[1]) : null;

        $items = $this->items($lines);
        $warnings = [];

        if ($items === []) {
            $warnings[] = 'The product on this Myntra invoice could not be read. Add the product before it is packed.';
        } elseif (in_array(true, array_column($items, 'guessed'), true)) {
            $warnings[] = 'The quantity on this Myntra invoice could not be read clearly and was taken as 1. Check it before packing.';
        }

        return LabelExtraction::fromArray([
            'pages' => [$page],
            'alt_code' => $packet,
            'order_number' => $order,
            'payable_amount' => $total,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'seller_gstin' => $gstin,
            'customer_name' => $name,
            'customer_state' => $state,
            'customer_pincode' => $pincode,
            'lines' => array_map(fn (array $i) => ['seller_sku' => $i['sku'], 'description' => $i['description'], 'quantity' => $i['quantity']], $items),
            'warnings' => $warnings,
        ]);
    }

    /**
     * The product rows of the invoice table: "SKU(size) - Brand Product,
     * Size: …", then an HSN line, then "1 Rs 385.00 …" whose first figure
     * is the quantity.
     *
     * @param  list<string>  $lines
     * @return list<array{sku: string, description: string|null, quantity: int, guessed: bool}>
     */
    private function items(array $lines): array
    {
        $start = TextLines::indexOf($lines, fn (string $l) => preg_match('/^\s*qty\b/i', $l) === 1) ?? 0;
        $end = TextLines::indexOf($lines, fn (string $l) => preg_match('/^\s*total\b/i', $l) === 1) ?? count($lines);
        $items = [];

        for ($i = $start; $i < $end; $i++) {
            if (preg_match('/^([A-Z0-9][A-Z0-9_]{5,39})\s*\(([^)]*)\)?\s*[-–—]\s*(.+)$/', $lines[$i], $m) !== 1
                && preg_match('/^([A-Z0-9][A-Z0-9_]{5,39})\s+[-–—]\s+(.+)$/', $lines[$i], $m) !== 1) {
                continue;
            }

            $description = end($m);
            $quantity = null;

            for ($j = $i + 1; $j < min($end, $i + 6); $j++) {
                if (preg_match('/^([0-9Il|]{1,3})\s*Rs/i', $lines[$j], $q) === 1) {
                    $quantity = (int) strtr($q[1], ['I' => '1', 'l' => '1', '|' => '1']);
                    break;
                }

                if (preg_match('/^HSN/i', $lines[$j]) !== 1 && preg_match('/^[A-Z0-9][A-Z0-9_]{5,39}\s*[\(-]/', $lines[$j]) !== 1) {
                    $description .= ' '.$lines[$j];
                }
            }

            $items[] = [
                'sku' => $m[1],
                'description' => trim((string) preg_replace('/\s*\(\s*\d*\s*$/', '', $description)),
                'quantity' => max(1, $quantity ?? 1),
                'guessed' => $quantity === null || $quantity < 1,
            ];
        }

        return $items;
    }

    /**
     * The AWB is the large barcode on the label (Code 128). The square
     * code repeats it after a leading zero; the printed digits under the
     * barcode are the last resort.
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

        foreach ($codes as $code) {
            if ($code['format'] === 'data_matrix' && preg_match('/^0?(\d{10,20})\|/', $code['value'], $m) === 1) {
                return $m[1];
            }
        }

        foreach ($lines as $line) {
            if (preg_match('/^(\d{12,20})$/', str_replace(' ', '', $line), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Myntra's invoice QR is a UPI link that carries the invoice's own
     * figures: invoiceNo, invoiceDate, am (the total), gstIn, and a url
     * whose last part is the order id in base64.
     *
     * @param  list<array{format: string, value: string}>  $codes
     * @return array<string, string>|null
     */
    private function qr(array $codes): ?array
    {
        foreach ($codes as $code) {
            if (! str_contains($code['value'], 'invoiceNo=') && ! str_starts_with($code['value'], 'upi://')) {
                continue;
            }

            // Read by hand: the link carries spaces and braces
            // (gstBrkUp={GST:15.43|…}) and "+05:30" must survive as printed.
            $value = $code['value'];
            $query = str_contains($value, '?') ? substr($value, strpos($value, '?') + 1) : $value;
            $fields = [];

            foreach (explode('&', $query) as $pair) {
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $fields[$key] = rawurldecode($value);
            }

            return array_filter($fields, fn ($v) => $v !== '');
        }

        return null;
    }

    /**
     * @param  array<string, string>  $qr
     */
    private function orderFromQr(array $qr): ?string
    {
        if (! isset($qr['url'])) {
            return null;
        }

        $last = basename((string) parse_url($qr['url'], PHP_URL_PATH));
        $decoded = base64_decode($last, true);

        return is_string($decoded) && preg_match('/^(\d{7})(\d{7})(\d{7})(?:\D|$)/', $decoded, $m) === 1
            ? "{$m[1]}-{$m[2]}-{$m[3]}"
            : null;
    }

    private function printedDate(string $all): ?string
    {
        if (preg_match('/invoice\s*date\s*[:.]?\s*(\d{1,2}\s+[A-Za-z]{3,9}\s+\d{4})/i', $all, $m) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($m[1])->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function total(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/^\s*total\b/i', $line) === 1 && preg_match_all('/Rs\.?\s*([\d,]+(?:\.\d+)?)/i', $line, $m) > 0) {
                return str_replace(',', '', end($m[1]));
            }
        }

        return null;
    }

    /**
     * A buyer's name as OCR gives it, less the stray marks read beside it.
     */
    private function name(string $text): ?string
    {
        $text = trim((string) preg_replace('/^(name\s*:)/i', '', trim($text)));

        if (preg_match("/^([\\p{L}][\\p{L} .']{1,59})/u", $text, $m) !== 1) {
            return null;
        }

        $name = trim($m[1], " .'");

        return mb_strlen($name) >= 2 ? $name : null;
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
