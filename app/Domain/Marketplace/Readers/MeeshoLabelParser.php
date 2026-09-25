<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Marketplace\Contracts\LabelTextParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * Meesho's "Sub Order Labels": one A4 page per parcel — the courier's
 * label on top with the AWB under its barcode, a "Product Details" row,
 * and Meesho's tax invoice below.
 */
final class MeeshoLabelParser implements LabelTextParser
{
    public function parse(string $text, int $page): ?LabelExtraction
    {
        $lines = TextLines::of($text);
        $details = TextLines::indexOf($lines, fn (string $l) => $l === 'Product Details');

        if ($details === null || TextLines::indexOf($lines, fn (string $l) => preg_match('/^SKU\b/', $l) === 1) === null) {
            return null;
        }

        $label = array_slice($lines, 0, $details);
        $labelText = implode("\n", $label);
        $warnings = [];

        // The AWB is printed under the barcode, the last thing before the
        // product table.
        $awb = null;

        foreach (array_reverse($label) as $line) {
            if (preg_match('/^[A-Z0-9]{8,24}$/', $line) === 1 && preg_match('/\d{5,}/', $line) === 1) {
                $awb = $line;
                break;
            }
        }

        $courier = Couriers::find($labelText) ?? Couriers::fromAwb($awb);

        $payment = match (true) {
            stripos($labelText, 'prepaid') !== false => 'prepaid',
            stripos($labelText, 'cod') !== false, stripos($labelText, 'payable amount') !== false => 'cod',
            default => null,
        };

        $rows = $this->rows($lines, $details);

        if ($rows === []) {
            $warnings[] = 'The product row could not be read on this label.';
        }

        $orderNumber = TextLines::after($lines, 'Purchase Order No.');

        if ($orderNumber === null && $rows !== []) {
            $orderNumber = preg_replace('/_\d+$/', '', $rows[0]['order']);
        }

        $total = null;

        foreach ($lines as $line) {
            if (preg_match('/^Total\b.*Rs\.?\s*([\d,]+(?:\.\d+)?)\s*$/i', $line, $m) === 1) {
                $total = str_replace(',', '', $m[1]);
            }
        }

        // The bill-to block wraps, so the state can run onto the next line;
        // the seller's block ("Sold by") always follows it.
        $state = preg_match('/Place of Supply:\s*([A-Za-z &.\-]+?)\s*(?:Sold by|,|$)/i', implode(' ', $lines), $m) === 1 ? trim($m[1]) : null;
        $gstin = preg_match('/GSTIN\s*[-:]\s*(\d{2}[A-Z0-9]{13})/', implode("\n", $lines), $m) === 1 ? $m[1] : null;

        return LabelExtraction::fromArray([
            'pages' => [$page],
            'awb' => $awb,
            'order_number' => $orderNumber,
            'courier' => $courier,
            'payment_mode' => $payment,
            'payable_amount' => $total,
            'invoice_number' => TextLines::after($lines, 'Invoice No.'),
            'invoice_date' => TextLines::after($lines, 'Invoice Date'),
            'customer_name' => TextLines::after($lines, 'Customer Address'),
            'customer_state' => $state,
            'seller_gstin' => $gstin,
            'lines' => array_map(fn (array $r) => ['seller_sku' => $r['sku'], 'description' => $r['size'], 'quantity' => $r['quantity']], $rows),
            'warnings' => array_merge($warnings, $awb === null ? ['No AWB was found under the barcode.'] : []),
        ]);
    }

    /**
     * The rows under "SKU  Size  Qty  Color  Order No.": the SKU and size
     * run together, then the quantity, the colour and the sub-order number.
     *
     * @param  list<string>  $lines
     * @return list<array{sku: string, size: string|null, quantity: int, order: string}>
     */
    private function rows(array $lines, int $from): array
    {
        $rows = [];
        $carry = '';

        for ($i = $from + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/^SKU\b/', $line) === 1) {
                continue;
            }

            if (str_starts_with($line, 'TAX INVOICE') || str_starts_with($line, 'BILL TO')) {
                break;
            }

            $candidate = trim($carry.' '.$line);

            if (preg_match('/^(?<rest>.+?)\s+(?<qty>\d{1,4})\s+(?<color>\S+)\s+(?<order>\d{6,}_\d+)$/u', $candidate, $m) === 1) {
                $sku = $m['rest'];
                $size = null;

                if (preg_match('/^(.*?)\s+(Free Size)$/i', $sku, $s) === 1) {
                    [$sku, $size] = [$s[1], $s[2]];
                }

                $rows[] = ['sku' => trim($sku), 'size' => $size, 'quantity' => (int) $m['qty'], 'order' => $m['order']];
                $carry = '';

                continue;
            }

            // A long SKU wraps onto the next line.
            $carry = $candidate;
        }

        return $rows;
    }
}
