<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\DTOs;

use App\Domain\Marketplace\Enums\PaymentMode;

/**
 * One parcel as read from its label: which pages of the file are its
 * paperwork, the codes on it, and what goes in it.
 */
final class LabelExtraction
{
    /**
     * @param  list<int>  $pages  1-based pages of the file, in order
     * @param  list<array{seller_sku: string, description: string|null, quantity: int}>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly array $pages,
        public readonly ?string $awb = null,
        public readonly ?string $altCode = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $courier = null,
        public readonly PaymentMode $paymentMode = PaymentMode::Unknown,
        public readonly ?string $payableAmount = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $invoiceDate = null,
        public readonly ?string $customerName = null,
        public readonly ?string $customerState = null,
        public readonly ?string $sellerGstin = null,
        public readonly array $lines = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $pages = array_values(array_unique(array_filter(
            array_map('intval', (array) ($data['pages'] ?? [])),
            fn (int $p) => $p > 0,
        )));
        sort($pages);

        $lines = [];

        foreach ((array) ($data['lines'] ?? []) as $line) {
            $sku = self::clean($line['seller_sku'] ?? null);
            $quantity = (int) ($line['quantity'] ?? 0);

            if ($sku === null) {
                continue;
            }

            $lines[] = [
                'seller_sku' => $sku,
                'description' => self::clean($line['description'] ?? null),
                'quantity' => max(1, $quantity),
            ];
        }

        return new self(
            pages: $pages,
            awb: self::code($data['awb'] ?? null),
            altCode: self::code($data['alt_code'] ?? null),
            orderNumber: self::clean($data['order_number'] ?? null),
            courier: self::clean($data['courier'] ?? null),
            paymentMode: PaymentMode::fromText(is_string($data['payment_mode'] ?? null) ? $data['payment_mode'] : null),
            payableAmount: self::amount($data['payable_amount'] ?? null),
            invoiceNumber: self::clean($data['invoice_number'] ?? null),
            invoiceDate: self::date($data['invoice_date'] ?? null),
            customerName: self::clean($data['customer_name'] ?? null),
            customerState: self::clean($data['customer_state'] ?? null),
            sellerGstin: self::gstin($data['seller_gstin'] ?? null),
            lines: $lines,
            warnings: array_values(array_filter(array_map(fn ($w) => self::clean($w), (array) ($data['warnings'] ?? [])))),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'pages' => $this->pages,
            'awb' => $this->awb,
            'alt_code' => $this->altCode,
            'order_number' => $this->orderNumber,
            'courier' => $this->courier,
            'payment_mode' => $this->paymentMode->value,
            'payable_amount' => $this->payableAmount,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => $this->invoiceDate,
            'customer_name' => $this->customerName,
            'customer_state' => $this->customerState,
            'seller_gstin' => $this->sellerGstin,
            'lines' => $this->lines,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Nothing on the page said which parcel this is.
     */
    public function isBlank(): bool
    {
        return $this->awb === null && $this->orderNumber === null;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : $value;
    }

    private static function code(mixed $value): ?string
    {
        $value = self::clean($value);

        return $value === null ? null : strtoupper(str_replace(' ', '', $value));
    }

    private static function gstin(mixed $value): ?string
    {
        $value = self::code($value);

        return $value !== null && preg_match('/^\d{2}[A-Z0-9]{13}$/', $value) === 1 ? $value : null;
    }

    private static function amount(mixed $value): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[^0-9.]/', '', $value) ?? '';

        return is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    /**
     * A date as YYYY-MM-DD, from whatever the label printed.
     */
    private static function date(mixed $value): ?string
    {
        $value = self::clean($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        if (preg_match('/^(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4})/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return null;
    }
}
