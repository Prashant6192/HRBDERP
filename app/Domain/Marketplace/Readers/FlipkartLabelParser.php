<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Marketplace\Contracts\LabelTextParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * Flipkart's shipping label with its tax invoice below it, one parcel per
 * page: the AWB printed beside the barcode, Flipkart's own tracking code
 * under a second barcode, and a "SKU ID | Description | QTY" table.
 */
final class FlipkartLabelParser implements LabelTextParser
{
    public function parse(string $text, int $page): ?LabelExtraction
    {
        $lines = TextLines::of($text);
        $all = implode("\n", $lines);
        $header = TextLines::indexOf($lines, fn (string $l) => str_starts_with($l, 'SKU ID'));

        if ($header === null || preg_match('/AWB No\.?\s*([A-Z0-9]+)/i', $all, $awbMatch) !== 1) {
            return null;
        }

        $awb = strtoupper($awbMatch[1]);
        $orderNumber = preg_match('/\b(OD\d{12,})\b/', $all, $m) === 1 ? $m[1] : null;

        // Flipkart's tracking id sits under the lower barcode; it is often
        // the AWB itself.
        $altCode = null;

        foreach ($lines as $line) {
            if (preg_match('/^(FM[A-Z]{2}\d{6,})$/', $line, $m) === 1 && $m[1] !== $awb) {
                $altCode = $m[1];
                break;
            }
        }

        $payment = match (true) {
            preg_match('/\bPREPAID\b/i', $all) === 1 => 'prepaid',
            preg_match('/\bCOD\b/', $all) === 1 => 'cod',
            default => null,
        };

        $taxInvoice = TextLines::indexOf($lines, fn (string $l) => str_starts_with($l, 'Tax Invoice')) ?? count($lines);
        $courier = Couriers::find(implode("\n", array_slice($lines, 0, min($header, $taxInvoice)))) ?? Couriers::fromAwb($awb);

        $rows = $this->rows($lines, $header, $altCode, $awb);
        $warnings = $rows === [] ? ['The SKU table could not be read on this label.'] : [];

        // The invoice's total quantity settles a single-row table.
        if (count($rows) === 1 && preg_match('/TOTAL QTY:\s*(\d+)/i', $all, $m) === 1) {
            $rows[0]['quantity'] = (int) $m[1];
        }

        $total = preg_match('/TOTAL PRICE:\s*([\d,]+(?:\.\d+)?)/i', $all, $m) === 1 ? str_replace(',', '', $m[1]) : null;
        $invoiceDate = preg_match('/Invoice Date:\s*(\d{2}-\d{2}-\d{4})/', $all, $m) === 1 ? $m[1] : null;
        $customer = preg_match('/^Name:\s*(.+?),?$/m', $all, $m) === 1 ? $m[1] : null;
        $state = preg_match('/\bIN-([A-Z]{2})\b/', $all, $m) === 1 ? $m[1] : null;
        $gstin = preg_match('/GSTIN:\s*(\d{2}[A-Z0-9]{13})/', $all, $m) === 1 ? $m[1] : null;

        return LabelExtraction::fromArray([
            'pages' => [$page],
            'awb' => $awb,
            'alt_code' => $altCode,
            'order_number' => $orderNumber,
            'courier' => $courier,
            'payment_mode' => $payment,
            'payable_amount' => $total,
            'invoice_number' => TextLines::after($lines, 'Invoice No:'),
            'invoice_date' => $invoiceDate,
            'customer_name' => $customer,
            'customer_state' => $state,
            'seller_gstin' => $gstin,
            'lines' => array_map(fn (array $r) => ['seller_sku' => $r['sku'], 'description' => $r['description'], 'quantity' => $r['quantity']], $rows),
            'warnings' => $warnings,
        ]);
    }

    /**
     * "1Medicated_Oil_300 | Harbanshram Medicated_Oil_300 Hair Oil", the
     * description running on over further lines, and the quantity as the
     * last bare number before the next row.
     *
     * @param  list<string>  $lines
     * @return list<array{sku: string, description: string, quantity: int}>
     */
    private function rows(array $lines, int $header, ?string $altCode, string $awb): array
    {
        $rows = [];
        $current = null;
        $expected = 1;

        for ($i = $header + 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if ($line === $altCode || $line === $awb || preg_match('/^FM[A-Z]{2}\d{6,}$/', $line) === 1 || str_starts_with($line, 'Tax Invoice') || str_starts_with($line, 'Not for resale')) {
                break;
            }

            $prefix = (string) $expected;

            if (str_starts_with($line, $prefix) && preg_match('/^'.$prefix.'(\S.*?)\s*\|\s*(.*)$/', $line, $m) === 1) {
                if ($current !== null) {
                    $rows[] = $this->close($current);
                }

                $current = ['sku' => trim($m[1]), 'description' => [trim($m[2])], 'numbers' => []];
                $expected++;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if (preg_match('/^\d{1,4}$/', $line) === 1) {
                $current['numbers'][] = (int) $line;
            }

            $current['description'][] = $line;
        }

        if ($current !== null) {
            $rows[] = $this->close($current);
        }

        return $rows;
    }

    /**
     * @param  array{sku: string, description: list<string>, numbers: list<int>}  $row
     * @return array{sku: string, description: string, quantity: int}
     */
    private function close(array $row): array
    {
        $quantity = $row['numbers'] === [] ? 1 : $row['numbers'][count($row['numbers']) - 1];
        $description = $row['description'];

        // The quantity was read as the last line of the description.
        if ($row['numbers'] !== [] && end($description) === (string) $quantity) {
            array_pop($description);
        }

        return ['sku' => $row['sku'], 'description' => trim(implode(' ', $description)), 'quantity' => max(1, $quantity)];
    }
}
