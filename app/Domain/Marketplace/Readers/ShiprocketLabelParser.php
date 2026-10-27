<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\Marketplace\Contracts\LabelSheetParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * A website order's shipping labels from Shiprocket.
 *
 * Shiprocket prints two labels side by side on an A4 page, each starting
 * "DELIVER To:" with the buyer, then the order number, COD or prepaid with
 * the amount to collect, "COURIER : …" (the courier chosen in Shiprocket),
 * "AWB # : …", and a "SKU | ITEM | QTY | PRICE" table whose long SKUs wrap
 * onto a later line ("(500ML-2P" … "acK)"). Each label is read on its own
 * and says which slot of the page it sits in, so it prints on its own.
 *
 * A label laid out another way (Shiprocket lets sellers change it) is still
 * read by what it says: "Ship To", "Order #", "SKU: …". What cannot be read
 * is left for the batch screen to fill in.
 */
final class ShiprocketLabelParser implements LabelSheetParser
{
    public function parse(string $text, int $page): ?LabelExtraction
    {
        return $this->parsePage($text, $page)[0] ?? null;
    }

    public function parsePage(string $text, int $page): array
    {
        $lines = TextLines::of($text);
        $starts = array_keys(array_filter($lines, fn (string $l) => preg_match('/^DELIVER\s*TO\b/i', $l) === 1));

        if ($starts === []) {
            $label = $this->label($lines, $page, null);

            return $label === null ? [] : [$label];
        }

        $labels = [];

        foreach ($starts as $n => $start) {
            $end = $starts[$n + 1] ?? count($lines);
            $label = $this->label(array_slice($lines, $start, $end - $start), $page, $n);

            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    /**
     * @param  list<string>  $lines  one label's lines
     */
    private function label(array $lines, int $page, ?int $slot): ?LabelExtraction
    {
        $all = implode("\n", $lines);

        if (preg_match('/\bAWB(?:\s*(?:No\.?|Number|#|Code))?\s*[:\-#]?\s*([A-Z0-9]{8,24})\b/i', $all, $m) !== 1) {
            return null;
        }

        $awb = strtoupper($m[1]);
        $orderNumber = preg_match('/\bOrder\s*(?:#|No\.?|Number|Id|ID)\s*[:\-]?\s*#?\s*([A-Za-z0-9][A-Za-z0-9\-_\/]{0,40})/i', $all, $m) === 1 ? $m[1] : null;

        $payment = match (true) {
            preg_match('/\bprepaid\b/i', $all) === 1 => 'prepaid',
            preg_match('/\bCOD\b|cash\s+on\s+delivery/i', $all) === 1 => 'cod',
            default => null,
        };

        $amount = null;

        foreach ([
            '/COLLECT\s*COD\s*[:\-]?\s*(?:Rs\.?|INR|₹)\s*([\d,]+(?:\.\d{1,2})?)/i',
            '/(?:COD\s*Amount|Collectable\s*Amount|Amount\s*to\s*(?:be\s*)?Collect(?:ed)?)\s*[:\-]?\s*(?:Rs\.?|INR|₹)?\s*([\d,]+(?:\.\d{1,2})?)/i',
            '/\b(?:Grand\s*)?Total(?:\s*Amount)?\s*[:\-]?\s*(?:\d+\s*)?(?:Rs\.?|INR|₹)\s*([\d,]+(?:\.\d{1,2})?)/i',
        ] as $pattern) {
            if (preg_match($pattern, $all, $m) === 1) {
                $amount = str_replace(',', '', $m[1]);
                break;
            }
        }

        [$customer, $state, $pincode] = $this->shipTo($lines);

        $invoice = preg_match('/Invoice\s*(?:No\.?|Number|#)\s*[:\-]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]{1,40})/i', $all, $m) === 1 ? $m[1] : null;
        $invoiceDate = preg_match('/Invoice\s*Date\s*[:\-]?\s*(\d{1,4}[\-\/.]\d{1,2}[\-\/.]\d{2,4})/i', $all, $m) === 1 ? $m[1] : null;
        $gstin = preg_match('/GSTIN\s*(?:No\.?)?\s*[:\-]?\s*(\d{2}[A-Z0-9]{13})/i', $all, $m) === 1 ? strtoupper($m[1]) : null;

        [$rows, $warnings] = $this->table($lines);

        if ($rows === []) {
            $rows = $this->skuLines($lines);
        }

        if ($rows === []) {
            $warnings[] = 'The product on this label could not be read. Type its SKU and quantity on the batch screen.';
        }

        return LabelExtraction::fromArray([
            'pages' => [$page],
            'slot' => $slot,
            'awb' => $awb,
            'order_number' => $orderNumber,
            'courier' => $this->courier($lines, $all, $awb),
            'payment_mode' => $payment,
            'payable_amount' => $payment === 'prepaid' ? '0' : $amount,
            'invoice_number' => $invoice,
            'invoice_date' => $invoiceDate,
            'customer_name' => $customer,
            'customer_state' => $state,
            'customer_pincode' => $pincode,
            'seller_gstin' => $gstin,
            'lines' => $rows,
            'warnings' => $warnings,
        ]);
    }

    /**
     * The courier booked in Shiprocket: its own line ("COURIER : Instant
     * pickup - Shadowfax"), named as the ERP names couriers when it is one
     * the ERP knows, else as printed.
     *
     * @param  list<string>  $lines
     */
    private function courier(array $lines, string $all, string $awb): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/^COURIER\s*(?:Name)?\s*:\s*(.+)$/i', $line, $m) === 1) {
                return Couriers::find($m[1]) ?? Couriers::fromAwb($awb) ?? mb_substr(trim($m[1]), 0, 60);
            }
        }

        // Shiprocket names itself on every label; the courier is the other
        // name printed.
        return Couriers::find(preg_replace('/ship\s*rocket/i', ' ', $all) ?? $all) ?? Couriers::fromAwb($awb);
    }

    /**
     * The buyer: the first line under "DELIVER To" / "Ship To", and the
     * state and PIN code in the lines up to the buyer's mobile number.
     *
     * @param  list<string>  $lines
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function shipTo(array $lines): array
    {
        $at = TextLines::indexOf($lines, fn (string $l) => preg_match('/^(?:Ship(?:ping)?\s*To|Deliver\s*To|Consignee)\b/i', $l) === 1);

        if ($at === null) {
            return [null, null, null];
        }

        // "DELIVER To: Shipped By (If undelivered, return to) :" — the
        // seller's caption shares the line.
        $sameLine = (string) preg_replace('/^(?:Ship(?:ping)?\s*To|Deliver\s*To|Consignee)\s*(?:Address)?\s*[:\-]?/i', '', $lines[$at]);
        $sameLine = trim((string) preg_replace('/Shipped\s*By.*$/i', '', $sameLine), ' ,:-');
        $name = $sameLine !== '' ? $sameLine : ($lines[$at + 1] ?? null);

        $block = [];

        for ($i = $at + 1; $i < min(count($lines), $at + 10); $i++) {
            $block[] = $lines[$i];

            if (preg_match('/^(?:MOBILE|PHONE|PH)\b/i', $lines[$i]) === 1) {
                break;
            }
        }

        $text = implode(' ', $block);
        $state = null;

        foreach (GstStateCodes::NAMES as $stateName) {
            if (stripos($text, $stateName) !== false) {
                $state = $stateName;
                break;
            }
        }

        $pincode = preg_match('/\b(\d{6})\b/', $text, $m) === 1 ? $m[1] : null;

        return [$name !== null ? mb_substr(trim($name, ' ,:'), 0, 120) : null, $state, $pincode];
    }

    /**
     * Shiprocket's "SKU | ITEM | QTY | PRICE" table. A row starts with its
     * SKU (capitals and hyphens, with any size in brackets); the item and
     * its quantity and price follow on the same line or the next; a SKU
     * too long for its column ends on a later line ("acK)"). The TOTAL
     * line's quantity settles a single row.
     *
     * @param  list<string>  $lines
     * @return array{0: list<array{seller_sku: string, description: string|null, quantity: int}>, 1: list<string>}
     */
    private function table(array $lines): array
    {
        $header = TextLines::indexOf($lines, fn (string $l) => preg_match('/^SKU\s+ITEM\b/i', $l) === 1);

        if ($header === null) {
            return [[], []];
        }

        $rows = [];
        $total = null;

        for ($i = $header + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^TOTAL\s*(\d+)\s*Rs/i', $line, $m) === 1) {
                $total = (int) $m[1];
                break;
            }

            if (preg_match('/^(?:SHIPPING|DISCOUNT|COD\s*CHARGES|Invoice\s*No)/i', $line) === 1) {
                continue;
            }

            $last = array_key_last($rows);

            // The end of a SKU that did not fit its column.
            if ($last !== null && $this->opens($rows[$last]['seller_sku']) && preg_match('/^\S{1,24}\)$/', $line) === 1) {
                $rows[$last]['seller_sku'] .= $line;

                continue;
            }

            if (preg_match('/^([A-Z0-9]+(?:[\-_\/.][A-Z0-9]+)+)(\s*\([^)]*\)?)?\s*(.*)$/', $line, $m) === 1) {
                $rows[] = ['seller_sku' => trim($m[1].$m[2]), 'description' => null, 'quantity' => null];
                $rest = trim($m[3]);

                if ($rest !== '') {
                    $this->item($rows[count($rows) - 1], $rest);
                }

                continue;
            }

            // An item line under its SKU, or a row with no SKU at all.
            if ($last !== null && $rows[$last]['description'] === null) {
                $this->item($rows[$last], $line);
            } elseif (preg_match('/Rs\.?\s*[\d,]+(?:\.\d+)?\s*$/i', $line) === 1) {
                $rows[] = ['seller_sku' => '', 'description' => null, 'quantity' => null];
                $this->item($rows[count($rows) - 1], $line);
                $rows[count($rows) - 1]['seller_sku'] = mb_substr((string) $rows[count($rows) - 1]['description'], 0, 120);
            }
        }

        $rows = array_values(array_filter($rows, fn (array $r) => $r['seller_sku'] !== ''));
        $warnings = [];

        if (count($rows) === 1 && $total !== null) {
            $rows[0]['quantity'] = $total;
        }

        $sum = array_sum(array_map(fn (array $r) => $r['quantity'] ?? 1, $rows));

        if ($total !== null && count($rows) > 1 && $sum !== $total) {
            $warnings[] = "The label says {$total} piece(s) in all but the rows add up to {$sum}. Check the quantities.";
        }

        return [array_map(fn (array $r) => [
            'seller_sku' => $r['seller_sku'],
            'description' => $r['description'],
            'quantity' => max(1, (int) ($r['quantity'] ?? 1)),
        ], $rows), $warnings];
    }

    /**
     * The item text of a row: its description, and the quantity printed
     * just before the price ("… - 300ml1 Rs.439.00" is one).
     *
     * @param  array{seller_sku: string, description: string|null, quantity: int|null}  $row
     */
    private function item(array &$row, string $text): void
    {
        if (preg_match('/^(.*?)(?<!\d)(\d{1,3})\s*Rs\.?\s*[\d,]+(?:\.\d+)?\s*$/i', $text, $m) === 1) {
            $row['description'] = rtrim($m[1], ' -.…') ?: null;
            $row['quantity'] = (int) $m[2];

            return;
        }

        $row['description'] = trim(preg_replace('/\s*Rs\.?\s*[\d,]+(?:\.\d+)?\s*$/i', '', $text) ?? $text) ?: null;
    }

    private function opens(string $sku): bool
    {
        return substr_count($sku, '(') > substr_count($sku, ')');
    }

    /**
     * Other layouts: each "SKU: …" with its quantity (on the same line, or
     * "Qty: n" close below), described by the line above it.
     *
     * @param  list<string>  $lines
     * @return list<array{seller_sku: string, description: string|null, quantity: int}>
     */
    private function skuLines(array $lines): array
    {
        $rows = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/\bSKU\s*(?:Code|ID)?\s*[:\-]\s*([A-Za-z0-9][A-Za-z0-9\-_\/.]*(?:\s[A-Za-z0-9\-_\/.]+)*?)(?=\s{2,}|\s*\||\s+(?:Qty|Quantity)\b|\s+\d{1,3}\s|\s*$)/i', $line, $m) !== 1) {
                continue;
            }

            $sku = trim($m[1]);
            $rest = trim(substr($line, strpos($line, $m[0]) + strlen($m[0])));
            $quantity = null;

            if (preg_match('/(?:Qty|Quantity)\s*[:\-]?\s*(\d{1,3})\b/i', $rest, $q) === 1 || preg_match('/^\|?\s*(\d{1,3})\b/', $rest, $q) === 1) {
                $quantity = (int) $q[1];
            }

            for ($j = $i + 1; $quantity === null && $j <= min($i + 2, count($lines) - 1); $j++) {
                if (preg_match('/^(?:Qty|Quantity)\s*[:\-]?\s*(\d{1,3})\b/i', $lines[$j], $q) === 1) {
                    $quantity = (int) $q[1];
                }
            }

            $before = $lines[$i - 1] ?? null;
            $description = $before !== null && preg_match('/\b(?:SKU|Qty|Product|Item|Price|Total)\b/i', $before) !== 1 ? $before : null;

            $rows[] = ['seller_sku' => $sku, 'description' => $description, 'quantity' => max(1, $quantity ?? 1)];
        }

        return $rows;
    }
}
