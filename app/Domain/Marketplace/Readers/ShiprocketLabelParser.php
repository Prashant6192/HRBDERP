<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Readers;

use App\Domain\Dispatch\Support\GstStateCodes;
use App\Domain\Marketplace\Contracts\LabelTextParser;
use App\Domain\Marketplace\DTOs\LabelExtraction;

/**
 * A website order's shipping label from Shiprocket, one parcel per page.
 *
 * Shiprocket lets each seller arrange its label, so nothing is read from a
 * fixed place: the AWB, the order number, the courier Shiprocket booked,
 * COD or prepaid with the amount to collect, the "Ship To" block and the
 * product lines ("SKU: …" with a quantity) are each found by what they
 * say. What cannot be read is left for the batch screen to fill in.
 */
final class ShiprocketLabelParser implements LabelTextParser
{
    public function parse(string $text, int $page): ?LabelExtraction
    {
        $lines = TextLines::of($text);
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

        if (preg_match('/(?:COD\s*Amount|Collectable\s*Amount|Amount\s*to\s*(?:be\s*)?Collect(?:ed)?|Collect)\s*[:\-]?\s*(?:Rs\.?|INR|₹)?\s*([\d,]+(?:\.\d{1,2})?)/i', $all, $m) === 1) {
            $amount = str_replace(',', '', $m[1]);
        } elseif (preg_match('/\b(?:Grand\s*)?Total(?:\s*Amount)?\s*[:\-]?\s*(?:Rs\.?|INR|₹)?\s*([\d,]+(?:\.\d{1,2})?)/i', $all, $m) === 1) {
            $amount = str_replace(',', '', $m[1]);
        }

        // Shiprocket names itself on every label; the courier it booked is
        // the other name printed.
        $courier = Couriers::find(preg_replace('/ship\s*rocket/i', ' ', $all) ?? $all) ?? Couriers::fromAwb($awb);

        [$customer, $state, $pincode] = $this->shipTo($lines);

        $invoice = preg_match('/Invoice\s*(?:No\.?|Number|#)\s*[:\-]?\s*([A-Za-z0-9][A-Za-z0-9\-\/]{1,40})/i', $all, $m) === 1 ? $m[1] : null;
        $invoiceDate = preg_match('/Invoice\s*Date\s*[:\-]?\s*(\d{1,4}[\-\/.]\d{1,2}[\-\/.]\d{2,4})/i', $all, $m) === 1 ? $m[1] : null;
        $gstin = preg_match('/GSTIN\s*(?:No\.?)?\s*[:\-]?\s*(\d{2}[A-Z0-9]{13})/i', $all, $m) === 1 ? strtoupper($m[1]) : null;

        $rows = $this->products($lines);

        return LabelExtraction::fromArray([
            'pages' => [$page],
            'awb' => $awb,
            'order_number' => $orderNumber,
            'courier' => $courier,
            'payment_mode' => $payment,
            'payable_amount' => $payment === 'prepaid' ? '0' : $amount,
            'invoice_number' => $invoice,
            'invoice_date' => $invoiceDate,
            'customer_name' => $customer,
            'customer_state' => $state,
            'customer_pincode' => $pincode,
            'seller_gstin' => $gstin,
            'lines' => $rows,
            'warnings' => $rows === [] ? ['The product on this label could not be read. Type its SKU and quantity on the batch screen.'] : [],
        ]);
    }

    /**
     * The buyer: the first line under "Ship To", and the state and PIN
     * code within the next few lines.
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

        $sameLine = trim((string) preg_replace('/^(?:Ship(?:ping)?\s*To|Deliver\s*To|Consignee)\s*(?:Address)?\s*[:\-]?/i', '', $lines[$at]));
        $name = $sameLine !== '' ? $sameLine : ($lines[$at + 1] ?? null);
        $block = implode(' ', array_slice($lines, $at, 7));

        $state = null;

        foreach (GstStateCodes::NAMES as $stateName) {
            if (stripos($block, $stateName) !== false) {
                $state = $stateName;
                break;
            }
        }

        $pincode = preg_match('/\b(\d{6})\b/', $block, $m) === 1 ? $m[1] : null;

        return [$name !== null ? mb_substr(trim($name, ' ,:'), 0, 120) : null, $state, $pincode];
    }

    /**
     * The product lines: each "SKU: …" with its quantity (on the same line,
     * or "Qty: n" close below), described by the line above it.
     *
     * @param  list<string>  $lines
     * @return list<array{seller_sku: string, description: string|null, quantity: int}>
     */
    private function products(array $lines): array
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
