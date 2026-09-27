<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Exceptions\OpeningStockException;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\PackagingMaterial;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Measurement\Models\Uom;
use App\Domain\Warehousing\Enums\WarehouseType;
use App\Domain\Warehousing\Models\Warehouse;
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
 * code, else by name. A code not on file, with a name and a unit, becomes
 * a new raw or packaging material named as the sheet names it; anything
 * else that does not match is reported, row by row, and nothing is booked
 * until the person has looked at every line.
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
     * A code the masters do not know, given with a name and a unit, is
     * read as a new material — exactly as the sheet names it — when the
     * person may add materials. Nothing is created here: the rows come
     * back marked new, and the material is added when the stock is posted.
     *
     * @return array{lines: list<array<string, mixed>>, problems: list<string>, new_materials: list<array{code: string, name: string, unit: string, rows: list<int>}>}
     */
    public function parse(string $path, string $kind, bool $mayCreate = false): array
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
        $newMaterials = [];

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
            $unitText = trim((string) ($get('unit') ?? ''));
            $unitCode = $this->unitCode($unitText);
            $uom = $unitCode === '' ? null : $uoms->get($unitCode);
            $newItem = null;

            if ($item === null) {
                $refusal = $this->refuseNew($kind, $mayCreate, $code, $name, $unitText, $uom, $newMaterials);

                if ($refusal !== null) {
                    $problems[] = "Row {$rowNo}: {$refusal}";

                    continue;
                }

                $newItem = ['code' => $code, 'name' => $name];
            }

            $label = $item?->name ?? $name;
            $quantity = $this->number($quantityRaw);

            if ($quantity === null || (float) $quantity <= 0) {
                $problems[] = "Row {$rowNo} ({$label}): the quantity \"{$quantityRaw}\" is not a number greater than zero.";

                continue;
            }

            if ($item !== null && $unitText !== '' && $uom === null) {
                $problems[] = "Row {$rowNo} ({$item->name}): the unit \"{$unitText}\" is not a unit on file; the stock unit {$item->stockUom?->code} will be used.";
            }

            if ($newItem !== null) {
                $key = strtoupper($code);
                $newMaterials[$key] ??= ['code' => $code, 'name' => $name, 'unit' => $uom->code, 'rows' => []];
                $newMaterials[$key]['rows'][] = $rowNo;
            }

            $lines[] = [
                'row' => $rowNo,
                'item_id' => $item?->id,
                'new_item' => $newItem,
                'item_label' => $item !== null ? "{$item->name} ({$item->code})" : "{$name} ({$code}) — new",
                'quantity' => $quantity,
                'uom_id' => $uom?->id ?? $item?->stock_uom_id,
                'batch_number' => trim((string) ($get('batch') ?? '')) ?: null,
                'manufactured_at' => $this->date($get('mfg')),
                'expiry_at' => $this->date($get('expiry')),
                'unit_cost' => $this->number($get('rate')),
                'remarks' => trim((string) ($get('remarks') ?? '')) ?: null,
            ];
        }

        return ['lines' => $lines, 'problems' => $problems, 'new_materials' => array_values($newMaterials)];
    }

    /**
     * The store kind a warehouse's sheet is for.
     */
    public static function kindFor(Warehouse $store): string
    {
        return match ($store->type) {
            WarehouseType::Packaging, WarehouseType::PackagingStaging => 'packaging',
            WarehouseType::FinishedGoods, WarehouseType::Marketplace => 'finished_goods',
            default => 'raw_material',
        };
    }

    /**
     * What a code the masters do not know becomes when the sheet brings
     * it: a raw material in a raw material store, a packaging material in
     * a packaging store. Products are never made from a counting sheet.
     */
    public static function newItemTypeFor(string $kind): ?ItemType
    {
        return match ($kind) {
            'packaging' => ItemType::PackagingMaterial,
            'finished_goods' => null,
            default => ItemType::RawMaterial,
        };
    }

    /**
     * The master class whose "create" permission adding one needs.
     *
     * @return class-string<Item>|null
     */
    public static function newItemClassFor(string $kind): ?string
    {
        return match (self::newItemTypeFor($kind)) {
            ItemType::PackagingMaterial => PackagingMaterial::class,
            ItemType::RawMaterial => RawMaterial::class,
            default => null,
        };
    }

    /**
     * Why a row naming a material not on file cannot become a new one, or
     * null when it can: it needs its own code, a name and a unit, the code
     * must be free, and one code is one material across the sheet.
     *
     * @param  array<string, array{code: string, name: string, unit: string, rows: list<int>}>  $seen
     */
    private function refuseNew(string $kind, bool $mayCreate, string $code, string $name, string $unitText, ?Uom $uom, array $seen): ?string
    {
        $type = self::newItemTypeFor($kind);
        $what = $code !== '' ? "code \"{$code}\"" : "\"{$name}\"";

        if ($type === null) {
            return "no product on file matches {$what}. Add the product under the masters first, or correct the code.";
        }

        if (! $mayCreate) {
            return "no material on file matches {$what}, and your role cannot add materials. Ask someone who may add ".strtolower($type->label()).'s, or correct the code.';
        }

        if ($code === '' || $name === '') {
            return "no material on file matches {$what}. To add it as a new material, fill in both its item code and its item name.";
        }

        if (mb_strlen($code) > 64) {
            return "the item code \"{$code}\" is longer than 64 characters.";
        }

        if ($unitText === '') {
            return "\"{$name}\" ({$code}) is new: fill in its unit (KG, G, L, ML, PCS …) so it can be added.";
        }

        if ($uom === null) {
            return "\"{$name}\" ({$code}) is new, but the unit \"{$unitText}\" is not a unit on file. Use KG, G, L, ML or PCS.";
        }

        $earlier = $seen[strtoupper($code)] ?? null;

        if ($earlier !== null && $this->key($earlier['name']) !== $this->key($name)) {
            return "code \"{$code}\" is \"{$earlier['name']}\" on row {$earlier['rows'][0]} but \"{$name}\" here. One code can only be one material.";
        }

        $taken = Item::withTrashed()->whereRaw('upper(code) = ?', [strtoupper($code)])->first(['id', 'code', 'name', 'type', 'is_active', 'deleted_at']);

        return match (true) {
            $taken === null => null,
            $taken->trashed() => "code \"{$code}\" belonged to a deleted material ({$taken->name}) and cannot be used again. Give this one a different code.",
            $taken->type === $type && ! $taken->is_active => "code \"{$code}\" is {$taken->name}, which is switched off. Switch it back on under the masters, then upload again.",
            default => "code \"{$code}\" is already {$taken->name}, a ".strtolower($taken->type->label()).'. Give this one a different code.',
        };
    }

    /**
     * A unit as people write it — Kg, Kgs, Ltr, gm, Nos — read as the code
     * on file.
     */
    private function unitCode(string $text): string
    {
        $code = strtoupper(preg_replace('/[\s.]/', '', $text) ?? '');

        return match ($code) {
            'KGS', 'KILO', 'KILOS', 'KILOGRAM', 'KILOGRAMS' => 'KG',
            'GM', 'GMS', 'GRM', 'GRMS', 'GRAM', 'GRAMS' => 'G',
            'LTR', 'LTRS', 'LT', 'LITRE', 'LITRES', 'LITER', 'LITERS' => 'L',
            'MLS', 'MILLILITRE', 'MILLILITRES' => 'ML',
            'NOS', 'NO', 'PC', 'PCE', 'PIECE', 'PIECES', 'UNIT', 'UNITS' => 'PCS',
            default => $code,
        };
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
