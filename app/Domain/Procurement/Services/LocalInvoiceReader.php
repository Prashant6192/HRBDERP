<?php

declare(strict_types=1);

namespace App\Domain\Procurement\Services;

use App\Domain\Procurement\Contracts\InvoiceReader;
use App\Domain\Procurement\DTOs\InvoiceExtraction;
use App\Domain\Procurement\Exceptions\InvoiceIntakeException;
use App\Support\Pdf\PdfText;

/**
 * The built-in bill reader: no network, no key.
 *
 * Reads the words off a PDF that was printed from accounting or billing
 * software (Tally, Busy, Marg, Zoho, SAP and the like carry their text as
 * text) and finds the seller, the bill number and date, and the goods
 * lines by the shape Indian GST bills share: a GSTIN on the letterhead, a
 * table headed Description / HSN / Quantity / Rate / Amount, and a serial
 * number at the start of every line. A scanned bill or a photo carries no
 * text and cannot be read this way.
 */
class LocalInvoiceReader implements InvoiceReader
{
    public const string MODEL = 'built-in reader';

    private const string GSTIN = '\b(\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z])\b';

    private const string DATE = '(\d{1,2}[\/\-.]\d{1,2}[\/\-.](?:\d{4}|\d{2})|\d{1,2}[\-\s][A-Za-z]{3,9}[\-\s,]+\d{2,4}|\d{4}-\d{2}-\d{2})';

    private const string NUMBER = '(\d{1,3}(?:,\d{2,3})+(?:\.\d+)?|\d+(?:\.\d+)?)';

    private const array UNITS = ['KG', 'KGS', 'KILOGRAM', 'KILOGRAMS', 'GM', 'GMS', 'G', 'GRAM', 'GRAMS', 'LTR', 'LTRS', 'LITRE', 'LITRES', 'LITER', 'L', 'ML', 'MLTR', 'PCS', 'PC', 'PIECE', 'PIECES', 'NOS', 'NO', 'UNIT', 'UNITS', 'BOX', 'BOXES', 'BAG', 'BAGS', 'DRUM', 'DRUMS', 'CAN', 'CANS', 'JAR', 'JARS', 'PKT', 'PKTS', 'BTL', 'BTLS', 'CTN', 'MTR', 'MTRS', 'M', 'SET', 'SETS', 'DOZ', 'ROLL', 'ROLLS', 'SHEET', 'SHEETS'];

    private const array LABELS = ['IRN', 'ACK', 'INVOICE', 'GSTIN', 'GST#', 'GST NO', 'PAN', 'CIN', 'STATE', 'CONTACT', 'E-MAIL', 'EMAIL', 'FSSAI', 'TEL', 'PHONE', 'MOB', 'CONSIGNEE', 'BUYER', 'SHIP TO', 'BILL TO', 'COPY', 'PAGE', 'ORIGINAL', 'DUPLICATE', 'TRIPLICATE', 'DELIVERY', 'DISPATCH', 'DATED', 'REFERENCE', 'ORDER', 'TERMS', 'DESTINATION', 'VESSEL', 'PORT', 'REMARK', 'PAYMENT', 'BANK', 'A/C', 'IFSC', 'MICR', 'BRANCH', 'UPI', 'DRUG LIC', 'WWW', 'HTTP', 'DECLARATION', 'AUTHORISED', 'SIGNATORY', 'SUBJECT TO', 'JURISDICTION', 'COMPUTER GENERATED', 'AMOUNT CHARGEABLE', 'RUPEES', 'TOTAL', 'TAXABLE', 'IGST', 'CGST', 'SGST', 'HSN', 'DESCRIPTION', 'QUANTITY', 'RATE', 'CUSTOMER', 'SALES ORDER', 'PROFORMA', 'QUOTATION', 'CHALLAN', 'WAREHOUSE', 'TRANSPORTER', 'VEHICLE', 'L.R.', 'INCOTERMS', 'CERTIFY', 'GOODS & SERVICE', 'GOODS AND SERVICE'];

    public function available(): bool
    {
        return true;
    }

    public function read(string $contents, string $mime, string $filename): InvoiceExtraction
    {
        if ($mime !== 'application/pdf') {
            throw new InvoiceIntakeException('The built-in reader reads PDF bills printed from billing software. A photo needs the Claude bill reader (ANTHROPIC_API_KEY), or enter the receipt by hand.');
        }

        $pdf = PdfText::read($contents);

        if ($pdf->isEmpty()) {
            throw new InvoiceIntakeException("{$filename} carries no text: it is a scan or a photo saved as PDF. Ask the supplier for the PDF their billing software produced, add the Claude bill reader for scans, or enter the receipt by hand.");
        }

        $rows = array_map(fn (array $r) => $r['text'], $pdf->rows);
        $text = $pdf->text;
        $warnings = ['Read by the built-in reader from the text of the PDF. Check each line against the bill before posting.'];

        $ours = strtoupper(trim((string) config('erp.company.gstin')));
        $gstins = $this->gstins($text);
        $buyerGstin = $ours !== '' && in_array($ours, $gstins, true) ? $ours : ($gstins[1] ?? null);
        $vendorGstin = collect($gstins)->first(fn (string $g) => $g !== $buyerGstin);

        $vendorName = $this->vendorName($rows, $vendorGstin, $buyerGstin);
        [$number, $date] = $this->numberAndDate($pdf);
        $lines = $this->lines($rows);
        $totals = $this->totals($rows);

        if ($lines === []) {
            $warnings[] = 'No goods lines could be made out from the text. Enter the lines by hand from the bill.';
        }

        return InvoiceExtraction::fromArray([
            'document_type' => $this->documentType($text),
            'vendor_name' => $vendorName,
            'vendor_gstin' => $vendorGstin,
            'buyer_gstin' => $buyerGstin,
            'vendor_address' => $this->vendorAddress($rows, $vendorName),
            'vendor_phone' => $this->first('/(?:Tel|Phone|Ph|Mob|Mobile|Contact)\.?\s*(?:No\.?)?\s*[:\-]?\s*(\+?[\d][\d \-]{7,}\d)/i', $text),
            'vendor_email' => $this->first('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $text),
            'invoice_number' => $number,
            'invoice_date' => $date,
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'currency' => 'INR',
            'lines' => $lines,
            'warnings' => $warnings,
        ], self::MODEL);
    }

    // -- Header ----------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function gstins(string $text): array
    {
        preg_match_all('/'.self::GSTIN.'/', strtoupper($text), $m);

        return array_values(array_unique($m[1]));
    }

    private function documentType(string $text): string
    {
        $upper = strtoupper($text);

        return match (true) {
            str_contains($upper, 'PROFORMA') => 'proforma',
            str_contains($upper, 'DELIVERY CHALLAN') => 'delivery_challan',
            str_contains($upper, 'QUOTATION') => 'quotation',
            str_contains($upper, 'TAX INVOICE') || str_contains($upper, 'E-INVOICE') || preg_match('/\bIRN\b/', $upper) === 1 || str_contains($upper, 'INVOICE') => 'tax_invoice',
            default => 'other',
        };
    }

    /**
     * The seller's name: a company-looking line that is not the buyer's,
     * the one printed most often (letterhead, bank account holder, the
     * "for ..." above the signature).
     *
     * @param  list<string>  $rows
     */
    private function vendorName(array $rows, ?string $vendorGstin, ?string $buyerGstin): ?string
    {
        $ours = $this->squash((string) config('erp.company.name'));
        $suffix = '/\b(PVT|PRIVATE|LTD|LIMITED|LLP|INC|INDUSTRIES|ENTERPRISES|ENTERPRISE|CHEMICALS|CHEMICAL|TRADERS|TRADING|CORPORATION|CORP|COMPANY|SPECIALITIES|SPECIALTIES|PHARMA|PHARMACEUTICALS|LABS|LABORATORIES|INTERNATIONAL|EXPORTS|IMPEX|AGENCIES|AGENCY|SONS|BROTHERS|BROS|MILLS|PACKAGING|POLYMERS|SCIENCES|SOLUTIONS|PRODUCTS|ORGANICS|AROMATICS|FRAGRANCES|ESSENTIALS|COSMETICS|HERBALS|NATURALS)\b/i';

        $buyerLines = [];

        foreach ($rows as $i => $row) {
            if (preg_match('/\b(BUYER|CONSIGNEE|BILL TO|SHIP TO|BILLED TO|SHIPPED TO|CUSTOMER)\b/i', $row) === 1) {
                foreach (range($i, min($i + 3, count($rows) - 1)) as $j) {
                    $buyerLines[$j] = true;
                }
            }

            if ($buyerGstin !== null && str_contains(strtoupper($row), $buyerGstin)) {
                foreach (range(max(0, $i - 6), $i) as $j) {
                    $buyerLines[$j] = true;
                }
            }
        }

        $counts = [];
        $firstSeen = [];

        foreach ($rows as $i => $row) {
            foreach (preg_split('/\s{2,}|\t/', $row) ?: [] as $cell) {
                $cell = trim(preg_replace('/^(for|A\/c Holder\'?s? Name\s*:?)\s+/i', '', $cell) ?? '');

                if ($cell === '' || strlen($cell) < 6 || strlen($cell) > 90 || preg_match($suffix, $cell) !== 1 || preg_match('/\d{6,}|@|:|^\(|\bFORMERLY\b/i', $cell) === 1) {
                    continue;
                }

                if (isset($buyerLines[$i]) || $this->isLabel($cell) || ($ours !== '' && str_contains($this->squash($cell), substr($ours, 0, 12)))) {
                    continue;
                }

                $key = $this->squash($cell);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $firstSeen[$key] ??= [$i, $cell];
            }
        }

        if ($counts === []) {
            // No company suffix anywhere: the first plain line above the seller's GSTIN.
            foreach ($rows as $i => $row) {
                if ($vendorGstin !== null && str_contains(strtoupper($row), $vendorGstin)) {
                    for ($j = $i - 1; $j >= max(0, $i - 8); $j--) {
                        $candidate = trim(preg_split('/\s{2,}|\t/', $rows[$j])[0] ?? '');

                        if ($candidate !== '' && preg_match('/[A-Za-z]{3}/', $candidate) === 1 && ! $this->isLabel($candidate) && ! isset($buyerLines[$j])) {
                            return $candidate;
                        }
                    }
                }
            }

            return null;
        }

        arsort($counts);
        $best = array_key_first($counts);
        $top = array_keys(array_filter($counts, fn (int $c) => $c === $counts[$best]));
        usort($top, fn (string $a, string $b) => $firstSeen[$a][0] <=> $firstSeen[$b][0]);

        return $firstSeen[$top[0]][1];
    }

    /**
     * @param  list<string>  $rows
     */
    private function vendorAddress(array $rows, ?string $vendorName): ?string
    {
        if ($vendorName === null) {
            return null;
        }

        $parts = [];

        foreach ($rows as $i => $row) {
            if (! str_contains($this->squash($row), $this->squash($vendorName))) {
                continue;
            }

            for ($j = $i + 1; $j <= min($i + 4, count($rows) - 1); $j++) {
                $cell = trim(preg_split('/\s{2,}|\t/', $rows[$j])[0] ?? '');

                if ($cell === '' || $this->isLabel($cell) || preg_match('/'.self::GSTIN.'/', strtoupper($cell)) === 1) {
                    break;
                }

                $parts[] = rtrim($cell, ' ,;');
            }

            break;
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The bill's own number and date: the value after the label on the
     * same row, or, as Tally prints it, in the cell beneath the label.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function numberAndDate(PdfText $pdf): array
    {
        $number = null;
        $date = null;
        $rows = $pdf->rows;

        foreach ($rows as $i => $row) {
            foreach ($row['cells'] as $c => $cell) {
                $t = $cell['t'];

                if ($number === null && preg_match('/^(?:PROFORMA\s+INVOICE|TAX\s+INVOICE|INVOICE|BILL|DC|CHALLAN)\s*(?:NO\.?|NUMBER|#)?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\/\-]*(?:\s+[A-Z0-9][A-Z0-9\/\-]*){0,2})\s*$/i', $t, $m) === 1 && preg_match('/[0-9]/', $m[1]) === 1 && ! $this->isLabel($m[1])) {
                    $number = $m[1];
                } elseif ($number === null && preg_match('/^(?:TAX\s+)?(?:INVOICE|BILL|DC|CHALLAN)\s*(?:NO\.?|NUMBER|#)\s*[:\-]?\s*$/i', $t) === 1) {
                    $number = $this->valueBeneath($rows, $i, $c, $cell['x']) ?? $this->nextCell($row, $c);
                }

                if ($date === null && preg_match('/^(?:INVOICE\s+DATE|BILL\s+DATE|DATED?)\s*[:\-]?\s*'.self::DATE.'\s*$/i', $t, $m) === 1) {
                    $date = $m[1];
                } elseif ($date === null && preg_match('/^(?:INVOICE\s+DATE|BILL\s+DATE|DATED)\s*[:\-]?\s*$/i', $t) === 1) {
                    $beneath = $this->valueBeneath($rows, $i, $c, $cell['x']) ?? $this->nextCell($row, $c);
                    $date = $beneath !== null && preg_match('/'.self::DATE.'/', $beneath, $m) === 1 ? $m[1] : null;
                }
            }
        }

        if ($number === null && preg_match('/(?:INVOICE|BILL)\s*(?:NO\.?|NUMBER|#)\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-]{2,})/i', $pdf->text, $m) === 1 && preg_match('/[0-9]/', $m[1]) === 1) {
            $number = $m[1];
        }

        if ($date === null) {
            // The first date that is not the e-invoice acknowledgement's.
            foreach ($rows as $row) {
                if (preg_match('/ACK\s*DATE/i', $row['text']) === 1) {
                    continue;
                }

                if (preg_match('/'.self::DATE.'/', $row['text'], $m) === 1) {
                    $date = $m[1];
                    break;
                }
            }
        }

        return [$number, $date];
    }

    /**
     * @param  list<array{y: float, cells: list<array{x: float, t: string}>, text: string}>  $rows
     */
    private function valueBeneath(array $rows, int $rowIndex, int $cellIndex, float $x): ?string
    {
        for ($j = $rowIndex + 1; $j <= min($rowIndex + 2, count($rows) - 1); $j++) {
            foreach ($rows[$j]['cells'] as $cell) {
                if (abs($cell['x'] - $x) <= 20 && trim($cell['t']) !== '' && ! $this->isLabel($cell['t'])) {
                    return trim($cell['t']);
                }
            }
        }

        return null;
    }

    /**
     * @param  array{y: float, cells: list<array{x: float, t: string}>, text: string}  $row
     */
    private function nextCell(array $row, int $cellIndex): ?string
    {
        $next = $row['cells'][$cellIndex + 1]['t'] ?? null;

        return $next !== null && ! $this->isLabel($next) ? trim($next) : null;
    }

    // -- Goods lines -------------------------------------------------------------

    /**
     * @param  list<string>  $rows
     * @return list<array<string, mixed>>
     */
    private function lines(array $rows): array
    {
        [$start, $end] = $this->tableBounds($rows);

        // Serial 1 starts a run of lines; 2, 3 … continue it. A number that
        // happens to start some other row ("1 of 1") starts a run of its
        // own; the run that yields the most goods lines is the table.
        $runs = [];
        $current = null;
        $closed = false;

        for ($i = $start; $i < $end; $i++) {
            $row = $rows[$i];

            $isPack = preg_match('/^\s*\d+(?:\.\d+)?\s*(?:'.implode('|', self::UNITS).')?\.?\s*[xX×]\s*\d/i', $row) === 1;

            if (! $isPack && (preg_match('/^\s*(\d{1,3})(?:[.)])?(?:\s+|(?=\d+\s*\*\s*\d+))(?!\d)(.*)$/', $row, $m) === 1 || preg_match('/^\s*(\d{1,3})\s*$/', $row, $m) === 1)) {
                $serial = (int) $m[1];

                if ($serial === 1) {
                    $runs[] = [];
                    $current = null;
                    $closed = false;
                }

                if ($runs !== [] && ! $closed && ($current === null ? $serial === 1 : $serial === $current['serial'] + 1)) {
                    if ($current !== null) {
                        $runs[count($runs) - 1][] = $current;
                    }

                    $current = ['serial' => $serial, 'rows' => [trim($m[2] ?? '')]];

                    continue;
                }
            }

            if ($current === null || $closed) {
                continue;
            }

            if ($this->isSummaryRow($row)) {
                $runs[count($runs) - 1][] = $current;
                $current = null;
                $closed = true;

                continue;
            }

            // Prose (terms, addresses, declarations) is not part of a line.
            if (preg_match('/\b[A-Za-z]{3,}\s+[A-Za-z]{3,}\s+[A-Za-z]{3,}\s+[A-Za-z]{3,}\b/', $row) === 1 && preg_match('/\d[\d,]*\.\d/', $row) !== 1 && preg_match('/\b(BATCH|LOT|MFG|MFD|EXP)/i', $row) !== 1) {
                $runs[count($runs) - 1][] = $current;
                $current = null;
                $closed = true;

                continue;
            }

            $current['rows'][] = $row;
        }

        if ($current !== null && $runs !== []) {
            $runs[count($runs) - 1][] = $current;
        }

        $best = [];

        foreach ($runs as $run) {
            $lines = [];

            foreach ($run as $item) {
                $line = $this->line($item['rows']);

                if ($line !== null) {
                    $lines[] = $line;
                }
            }

            $score = fn (array $ls) => count($ls) * 10 + count(array_filter($ls, fn ($l) => $l['quantity'] !== null && ($l['hsn'] !== null || $l['amount'] !== null)));

            if ($score($lines) > $score($best)) {
                $best = $lines;
            }
        }

        return $best;
    }

    /**
     * From the table header to the first total; the whole text when no
     * header can be found.
     *
     * @param  list<string>  $rows
     * @return array{0: int, 1: int}
     */
    private function tableBounds(array $rows): array
    {
        $start = 0;

        foreach ($rows as $i => $row) {
            if (preg_match('/\bDESCRIPTION\b/i', $row) !== 1) {
                continue;
            }

            $nearby = implode(' ', array_slice($rows, $i, 8));

            if (preg_match('/\b(HSN|QTY|QUANTITY|RATE|AMOUNT|UOM)\b/i', $nearby) === 1) {
                $start = $i + 1;
                break;
            }
        }

        return [$start, count($rows)];
    }

    private function isSummaryRow(string $row): bool
    {
        return preg_match('/^\s*(GRAND\s+TOTAL|TOTAL(?![A-Z])|SUB[\s\-]?TOTAL|AMOUNT\s+CHARGEABLE|OUTPUT\s+(IGST|CGST|SGST)|(IGST|CGST|SGST)\s*[@:\d]|TAXABLE\s+VALUE|ROUND(ED)?\s+OFF|LESS\s*:|ADD\s*:)/i', $row) === 1
            || preg_match('/^\s*(IGST|CGST|SGST)\s+[\d,]+\.\d{2}\s*$/i', $row) === 1
            || preg_match('/^\s*[\d,]+\.\d{2}\s*$/', $row) === 1;
    }

    /**
     * One goods line from the rows that make it up.
     *
     * @param  list<string>  $rows
     * @return array<string, mixed>|null
     */
    private function line(array $rows): ?array
    {
        $joined = trim(implode("\n", $rows));
        $joined = preg_replace('/\s*\n\s*/', "\n", $joined) ?? $joined;

        // Packing such as "2 BAG X 25 KG" describes the pack, not the quantity.
        $pack = null;
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:'.implode('|', self::UNITS).')?\.?\s*[xX×]\s*(\d+(?:\.\d+)?)\s*(?:'.implode('|', self::UNITS).')?\b\.?/i', $joined, $pm) === 1) {
            $pack = $pm[0];
        }
        $working = $pack === null ? $joined : str_replace($pack, ' ', $joined);

        // A "1*1" style packages column and the batch line are not the description.
        $working = preg_replace('/\b\d+\s*\*\s*\d+\b/', ' ', $working) ?? $working;
        $batch = null;
        if (preg_match('/\b(?:BATCH|LOT|B\.?\s?NO)\.?\s*(?:NO\.?|#)?\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-\.]{1,})/i', $working, $bm) === 1 && strtoupper($bm[1]) !== 'NO') {
            $batch = $bm[1];
            $working = str_replace($bm[0], ' ', $working);
        }

        $manufactured = $this->first('/\b(?:MFG|MFD|MANUFACTURED?|MANUFACTURING)\.?\s*(?:DATE|DT|ON)?\.?\s*[:\-]?\s*'.self::DATE.'/i', $working);
        $expiry = $this->first('/\b(?:EXP|EXPIRY|EXPIRES|BEST\s+BEFORE|USE\s+BY)\.?\s*(?:DATE|DT|ON)?\.?\s*[:\-]?\s*'.self::DATE.'/i', $working);

        $hsn = $this->first('/(?<![\d.,])(\d{8}|\d{6})(?![\d.,])/', $working);
        if ($hsn !== null) {
            $working = preg_replace('/(?<![\d.,])'.$hsn.'(?![\d.,])/', ' ', $working, 1) ?? $working;
        }

        // Quantity: the first number with a unit on it.
        $quantity = null;
        $unit = null;
        $units = implode('|', self::UNITS);
        if (preg_match('/(?<![\d.,A-Z\/\-])'.self::NUMBER.'\s*('.$units.')(?![A-Z0-9])\.?/i', $working, $qm) === 1) {
            $quantity = $qm[1];
            $unit = $qm[2];
            // Tally repeats the quantity under the batch; every copy goes.
            $working = preg_replace('/(?<![\d.,A-Z\/\-])'.preg_quote($quantity, '/').'\s*('.$units.')(?![A-Z0-9])\.?/i', ' ', $working) ?? $working;
        }

        // Whatever numbers are left, in print order: the rate, then the amount.
        preg_match_all('/(?<![\d.,A-Z\/\-])'.self::NUMBER.'(?![\d.,A-Z\/\-])/i', $working, $nm, PREG_OFFSET_CAPTURE);
        $numbers = array_values(array_filter($nm[1], fn (array $n) => str_contains($n[0], '.') || str_contains($n[0], ',')));
        $rate = null;
        $amount = null;

        if ($quantity !== null && count($numbers) >= 2) {
            $values = array_map(fn (array $n) => (float) str_replace(',', '', $n[0]), $numbers);
            $q = (float) str_replace(',', '', $quantity);
            $rate = $numbers[0][0];
            $r = $values[0];

            // The amount is the printed number nearest quantity × rate; failing
            // that, the last one. The two may also be printed the other way
            // round (amount, per, rate).
            $expected = $q * $r;
            $nearest = null;

            foreach ($values as $k => $v) {
                if ($k > 0 && $expected > 0 && abs($v - $expected) <= 0.02 * $expected && ($nearest === null || abs($v - $expected) < abs($values[$nearest] - $expected))) {
                    $nearest = $k;
                }
            }

            $amount = $numbers[$nearest ?? count($numbers) - 1][0];
            $a = (float) str_replace(',', '', $amount);

            if ($nearest === null && $q > 0 && abs($a * $q - $r) <= 0.02 * max(1, $r)) {
                [$rate, $amount] = [$amount, $rate];
            }
        } elseif (count($numbers) === 1) {
            $amount = $numbers[0][0];
        }

        // The description: the words of the first row, before any number column.
        $description = null;

        foreach (preg_split('/\n/', $working) ?: [] as $piece) {
            $piece = trim(preg_replace('/\s{2,}.*$/', '', trim($piece)) ?? '');
            $piece = trim(preg_replace('/\s+(?:'.$units.')\.?$/i', '', $piece) ?? '');
            $piece = trim(preg_replace('/(?<![A-Z])\d[\d,]*[.,]\d+\s*%?$/i', '', $piece) ?? '');

            if ($piece !== '' && preg_match('/[A-Za-z]{3}/', $piece) === 1 && ! $this->isLabel($piece)) {
                $description = $piece;
                break;
            }
        }

        if ($description === null) {
            return null;
        }

        return [
            'description' => $description,
            'hsn' => $hsn,
            'quantity' => $quantity,
            'unit' => $unit,
            'rate' => $rate,
            'amount' => $amount,
            'batch' => $batch,
            'manufactured_at' => $manufactured,
            'expiry_at' => $expiry,
        ];
    }

    /**
     * @param  list<string>  $rows
     * @return array{subtotal: string|null, tax: string|null, total: string|null}
     */
    private function totals(array $rows): array
    {
        $tax = 0.0;
        $taxSeen = false;
        $total = null;
        $subtotal = null;
        $afterTotal = false;

        foreach ($rows as $row) {
            if (preg_match('/^\s*(?:OUTPUT\s+)?(IGST|CGST|SGST)\b.*?'.self::NUMBER.'\s*$/i', $row, $m) === 1 && preg_match('/^\s*(IGST|CGST|SGST)\s+[\d,]+\.\d{2}\s*$|@|%/i', $row) === 1) {
                $tax += (float) str_replace(',', '', $m[2]);
                $taxSeen = true;
            }

            $afterTotal = isset($afterTotal) && $afterTotal && preg_match('/^[\s\d,.\t]+$/', $row) === 1;

            if ($afterTotal || preg_match('/^\s*(?:GRAND\s+TOTAL|TOTAL\s+INVOICE\s+AMOUNT|TOTAL\s+AMOUNT|TOTAL(?![A-Z])|NET\s+AMOUNT|AMOUNT\s+PAYABLE)/i', $row) === 1) {
                $afterTotal = true;
                preg_match_all('/'.self::NUMBER.'/', $row, $nm);
                $candidates = array_map(fn (string $n) => (float) str_replace(',', '', $n), $nm[1]);
                $candidates = array_filter($candidates, fn (float $n) => $n > 0);

                if ($candidates !== []) {
                    $total = max($total ?? 0.0, max($candidates));
                }
            } else {
                $afterTotal = false;
            }

            if ($subtotal === null && preg_match('/^\s*(?:SUB[\s\-]?TOTAL|TAXABLE\s+(?:VALUE|AMOUNT))\b.*?'.self::NUMBER.'\s*$/i', $row, $m) === 1) {
                $subtotal = (float) str_replace(',', '', $m[1]);
            }
        }

        if ($subtotal === null && $total !== null && $taxSeen) {
            $subtotal = $total - $tax;
        }

        $fmt = fn (?float $n) => $n === null ? null : number_format($n, 2, '.', '');

        return ['subtotal' => $fmt($subtotal), 'tax' => $taxSeen ? $fmt($tax) : null, 'total' => $fmt($total)];
    }

    // -- Helpers ---------------------------------------------------------------

    private function first(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) === 1 ? trim($m[1]) : null;
    }

    private function isLabel(string $text): bool
    {
        static $pattern = null;
        $pattern ??= '/(?<![A-Z])(?:'.implode('|', array_map(fn (string $l) => preg_quote($l, '/'), self::LABELS)).')(?![A-Z])/';
        $upper = strtoupper(trim($text));

        // A label starts the text, or sits in a short text such as "State Name : Uttar Pradesh".
        return preg_match('/^\W*'.substr($pattern, 1), $upper) === 1
            || (strlen($upper) < 40 && preg_match($pattern, $upper) === 1);
    }

    private function squash(string $text): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($text)) ?? '';
    }
}
