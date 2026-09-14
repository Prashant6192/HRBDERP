<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\Measurement\Models\Uom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Old stock on a spreadsheet.
 *
 * The factory counts what is on the shelf into a sheet — one row per
 * batch — and uploads it. Each row is matched to the material on file by
 * code, else by name; what does not match is reported, row by row, and
 * nothing is booked until the person has looked at every line.
 */
class OpeningStockSheetService
{
    public const array COLUMNS = ['Item code', 'Item name', 'Batch no', 'Quantity', 'Unit', 'Mfg date', 'Expiry date', 'Rate per unit', 'Remarks'];

    /**
     * @return list<ItemType>
     */
    public static function typesFor(string $kind): array
    {
        return match ($kind) {
            'packaging' => [ItemType::PackagingMaterial],
            'finished_goods' => [ItemType::FinishedGood, ItemType::SemiFinished],
            default => [ItemType::RawMaterial, ItemType::Consumable],
        };
    }

    /**
     * A workbook to fill in: the columns, two example rows, and a second
     * sheet listing every material of the kind with its code and unit.
     */
    public function template(string $kind): string
    {
        $items = $this->items($kind);
        $book = new Spreadsheet;

        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Opening stock');
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);

        $examples = $items->take(2)->values();
        $row = 2;

        foreach ($examples as $item) {
            $sheet->fromArray([$item->code, $item->name, 'OLD-'.$item->code.'-1', 100, $item->stockUom?->code, '2026-06-01', '2028-05-31', $item->standard_cost ?? '', 'Example row: replace or delete'], null, "A{$row}");
            $row++;
        }

        foreach (['A' => 14, 'B' => 36, 'C' => 18, 'D' => 12, 'E' => 8, 'F' => 12, 'G' => 12, 'H' => 14, 'I' => 30] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->getStyle('F2:G200')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        $list = $book->createSheet();
        $list->setTitle('Materials on file');
        $list->fromArray(['Item code', 'Item name', 'Stock unit'], null, 'A1');
        $list->getStyle('A1:C1')->getFont()->setBold(true);
        $list->fromArray($items->map(fn (Item $i) => [$i->code, $i->name, $i->stockUom?->code])->values()->all(), null, 'A2');
        $list->getColumnDimension('A')->setWidth(14);
        $list->getColumnDimension('B')->setWidth(40);

        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'opening-stock');
        (new Xlsx($book))->save($path);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * Rows of an uploaded sheet, matched to the masters.
     *
     * @return array{lines: list<array<string, mixed>>, problems: list<string>}
     */
    public function parse(string $path, string $kind): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
        } catch (Throwable $e) {
            throw new OpeningStockException('The file could not be read as a spreadsheet. Upload the .xlsx template filled in.');
        }

        $rows = $sheet->toArray(null, true, false, false);
        $header = array_map(fn ($h) => $this->key((string) $h), $rows[0] ?? []);
        $col = fn (string ...$names) => collect($names)->map(fn ($n) => array_search($this->key($n), $header, true))->first(fn ($i) => $i !== false);

        $columns = [
            'code' => $col('Item code', 'Code'),
            'name' => $col('Item name', 'Name', 'Material', 'Description'),
            'batch' => $col('Batch no', 'Batch', 'Batch number', 'Lot'),
            'quantity' => $col('Quantity', 'Qty'),
            'unit' => $col('Unit', 'UOM'),
            'mfg' => $col('Mfg date', 'Manufactured', 'Manufacturing date', 'Mfd'),
            'expiry' => $col('Expiry date', 'Expiry', 'Exp', 'Best before'),
            'rate' => $col('Rate per unit', 'Rate', 'Unit cost', 'Cost'),
            'remarks' => $col('Remarks', 'Notes'),
        ];

        if ($columns['quantity'] === null || ($columns['code'] === null && $columns['name'] === null)) {
            throw new OpeningStockException('The sheet needs at least an "Item code" (or "Item name") column and a "Quantity" column. Download the template to see the layout.');
        }

        $items = $this->items($kind);
        $byCode = $items->keyBy(fn (Item $i) => strtoupper(trim($i->code)));
        $byName = $items->keyBy(fn (Item $i) => $this->key($i->name));
        $uoms = Uom::query()->active()->get(['id', 'code'])->keyBy(fn (Uom $u) => strtoupper($u->code));

        $lines = [];
        $problems = [];

        foreach (array_slice($rows, 1, null, true) as $index => $row) {
            $rowNo = $index + 1;
            $get = fn (string $key) => $columns[$key] === null ? null : $row[$columns[$key]] ?? null;
            $code = trim((string) ($get('code') ?? ''));
            $name = trim((string) ($get('name') ?? ''));
            $quantityRaw = $get('quantity');

            if ($code === '' && $name === '' && ($quantityRaw === null || trim((string) $quantityRaw) === '')) {
                continue;
            }

            if (str_starts_with(strtolower(trim((string) ($get('remarks') ?? ''))), 'example row')) {
                continue;
            }

            $item = ($code !== '' ? $byCode->get(strtoupper($code)) : null) ?? ($name !== '' ? $byName->get($this->key($name)) : null);

            if ($item === null) {
                $problems[] = "Row {$rowNo}: no material on file matches ".($code !== '' ? "code \"{$code}\"" : "\"{$name}\"").'. Add it under the masters first, or correct the code.';

                continue;
            }

            $quantity = $this->number($quantityRaw);

            if ($quantity === null || (float) $quantity <= 0) {
                $problems[] = "Row {$rowNo} ({$item->name}): the quantity \"{$quantityRaw}\" is not a number greater than zero.";

                continue;
            }

            $unitCode = strtoupper(trim((string) ($get('unit') ?? '')));
            $uom = $unitCode === '' ? null : $uoms->get($unitCode);

            if ($unitCode !== '' && $uom === null) {
                $problems[] = "Row {$rowNo} ({$item->name}): the unit \"{$unitCode}\" is not a unit on file; the stock unit {$item->stockUom?->code} will be used.";
            }

            $lines[] = [
                'row' => $rowNo,
                'item_id' => $item->id,
                'item_label' => "{$item->name} ({$item->code})",
                'quantity' => $quantity,
                'uom_id' => $uom?->id ?? $item->stock_uom_id,
                'batch_number' => trim((string) ($get('batch') ?? '')) ?: null,
                'manufactured_at' => $this->date($get('mfg')),
                'expiry_at' => $this->date($get('expiry')),
                'unit_cost' => $this->number($get('rate')),
                'remarks' => trim((string) ($get('remarks') ?? '')) ?: null,
            ];
        }

        return ['lines' => $lines, 'problems' => $problems];
    }

    /**
     * @return Collection<int, Item>
     */
    private function items(string $kind): Collection
    {
        return Item::query()->active()->whereIn('type', array_map(fn (ItemType $t) => $t->value, self::typesFor($kind)))
            ->with('stockUom:id,code')->orderBy('name')->get(['id', 'code', 'name', 'type', 'stock_uom_id', 'standard_cost']);
    }

    private function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($text)) ?? '';
    }

    private function number(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $value)) ?? '';

        return is_numeric($clean) ? $clean : null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 20000) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
        }

        $text = trim((string) $value);

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/', $text, $m) === 1) {
            $year = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        try {
            return CarbonImmutable::parse($text)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
