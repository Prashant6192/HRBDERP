<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTOs;

use Carbon\CarbonImmutable;

/**
 * What was read off the document that came with a consignment: the ERP's
 * own challan, a transporter's lorry receipt, or an invoice for the move.
 *
 * @phpstan-type Line array{description: string, quantity: string|null, batch: string|null}
 */
final class ChallanExtraction
{
    /**
     * @param  list<Line>  $lines
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $transferNumber,
        public ?string $challanCode,
        public ?string $transporter,
        public ?string $lrNumber,
        public ?string $vehicle,
        public ?string $date,
        public array $lines,
        public array $warnings = [],
        public ?string $model = null,
    ) {}

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
                'quantity' => self::number($line['quantity'] ?? null),
                'batch' => self::text($line['batch'] ?? null),
            ];
        }

        $number = self::text($data['transfer_number'] ?? null);
        $code = self::text($data['challan_code'] ?? null);

        return new self(
            transferNumber: $number === null ? null : (preg_match('/TRF-\d{4}-\d{5}/i', $number, $m) === 1 ? strtoupper($m[0]) : strtoupper($number)),
            challanCode: $code === null ? null : strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''),
            transporter: self::text($data['transporter'] ?? null),
            lrNumber: self::text($data['lr_number'] ?? null),
            vehicle: self::text($data['vehicle'] ?? null),
            date: self::date($data['date'] ?? null),
            lines: $lines,
            warnings: array_values(array_filter(array_map(fn ($w) => self::text($w), (array) ($data['warnings'] ?? [])))),
            model: $model,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'transfer_number' => $this->transferNumber,
            'challan_code' => $this->challanCode,
            'transporter' => $this->transporter,
            'lr_number' => $this->lrNumber,
            'vehicle' => $this->vehicle,
            'date' => $this->date,
            'lines' => $this->lines,
            'warnings' => $this->warnings,
            'model' => $this->model,
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
}
