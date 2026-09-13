<?php

declare(strict_types=1);

namespace App\Domain\Procurement\DTOs;

use Carbon\CarbonImmutable;

/**
 * What was read off a supplier's bill. Nothing here is trusted until a
 * person has looked at it on the goods receipt screen.
 *
 * @phpstan-type Line array{description: string, hsn: string|null, quantity: string|null, unit: string|null, rate: string|null, amount: string|null, batch: string|null, manufactured_at: string|null, expiry_at: string|null}
 */
final class InvoiceExtraction
{
    /**
     * @param  list<Line>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $vendorName,
        public ?string $vendorGstin,
        public ?string $vendorAddress,
        public ?string $vendorPhone,
        public ?string $vendorEmail,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public ?string $subtotal,
        public ?string $tax,
        public ?string $total,
        public string $currency,
        public array $lines,
        public array $warnings = [],
        public ?string $model = null,
        /** tax_invoice, proforma, delivery_challan, quotation, other — or null when unsure. */
        public ?string $documentType = null,
        /** The GSTIN printed under "Buyer" / "Consignee" / "Bill to": ours, normally. */
        public ?string $buyerGstin = null,
    ) {}

    public const array DOCUMENT_TYPES = ['tax_invoice', 'proforma', 'delivery_challan', 'quotation', 'other'];

    /**
     * A proforma or a quotation says what will be supplied, not what was.
     */
    public function isProvisional(): bool
    {
        return in_array($this->documentType, ['proforma', 'quotation'], strict: true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?string $model = null): self
    {
        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (! is_array($line) || trim((string) ($line['description'] ?? '')) === '') {
                continue;
            }

            $lines[] = [
                'description' => trim((string) $line['description']),
                'hsn' => self::text($line['hsn'] ?? null),
                'quantity' => self::number($line['quantity'] ?? null),
                'unit' => self::text($line['unit'] ?? null),
                'rate' => self::number($line['rate'] ?? null),
                'amount' => self::number($line['amount'] ?? null),
                'batch' => self::text($line['batch'] ?? null),
                'manufactured_at' => self::date($line['manufactured_at'] ?? null),
                'expiry_at' => self::date($line['expiry_at'] ?? null),
            ];
        }

        return new self(
            vendorName: self::text($data['vendor_name'] ?? null),
            vendorGstin: self::gstin($data['vendor_gstin'] ?? null),
            vendorAddress: self::text($data['vendor_address'] ?? null),
            vendorPhone: self::text($data['vendor_phone'] ?? null),
            vendorEmail: self::text($data['vendor_email'] ?? null),
            invoiceNumber: self::text($data['invoice_number'] ?? null),
            invoiceDate: self::date($data['invoice_date'] ?? null),
            subtotal: self::number($data['subtotal'] ?? null),
            tax: self::number($data['tax'] ?? null),
            total: self::number($data['total'] ?? null),
            currency: self::text($data['currency'] ?? null) ?? 'INR',
            lines: $lines,
            warnings: array_values(array_filter(array_map(fn ($w) => self::text($w), (array) ($data['warnings'] ?? [])))),
            model: $model,
            documentType: in_array($data['document_type'] ?? null, self::DOCUMENT_TYPES, strict: true) ? $data['document_type'] : null,
            buyerGstin: self::gstin($data['buyer_gstin'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vendor_name' => $this->vendorName,
            'vendor_gstin' => $this->vendorGstin,
            'vendor_address' => $this->vendorAddress,
            'vendor_phone' => $this->vendorPhone,
            'vendor_email' => $this->vendorEmail,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => $this->invoiceDate,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'total' => $this->total,
            'currency' => $this->currency,
            'lines' => $this->lines,
            'warnings' => $this->warnings,
            'model' => $this->model,
            'document_type' => $this->documentType,
            'buyer_gstin' => $this->buyerGstin,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || strtolower($value) === 'null' ? null : $value;
    }

    private static function number(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $text)) ?? '';

        return is_numeric($clean) ? $clean : null;
    }

    private static function date(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        // Indian bills write the day first (05/09/2026 is 5 September);
        // only an impossible day says a bill was printed month-first.
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/', $text, $m) === 1) {
            [$first, $second, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            $year = $year < 100 ? 2000 + $year : $year;
            [$day, $month] = $first <= 12 && $second > 12 ? [$second, $first] : [$first, $second];

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }

        try {
            return CarbonImmutable::parse($text)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function gstin(mixed $value): ?string
    {
        $text = self::text($value);

        if ($text === null) {
            return null;
        }

        $text = strtoupper(preg_replace('/\s+/', '', $text) ?? '');

        return strlen($text) === 15 ? $text : null;
    }
}
