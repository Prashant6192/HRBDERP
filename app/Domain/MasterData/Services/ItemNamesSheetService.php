<?php

declare(strict_types=1);

namespace App\Domain\MasterData\Services;

use App\Domain\Inventory\Models\StockBalance;
use App\Domain\MasterData\Enums\ItemType;
use App\Domain\MasterData\Exceptions\ItemNamesSheetException;
use App\Domain\MasterData\Models\Item;
use App\Domain\MasterData\Models\RawMaterial;
use App\Domain\Warehousing\Models\Warehouse;
use App\Models\User;
use App\Support\Math\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * The raw material store's ingredients on a spreadsheet, to rename them in
 * one go.
 *
 * The store downloads every ingredient with its ERP id, code and name,
 * corrects codes and names in Excel, and uploads the sheet. Each row is
 * found by its id, never by its code, so a changed code still lands on the
 * right ingredient. Nothing changes until the person has looked at every
 * change; then the changes go in together, each one recorded in the audit
 * trail. Two ingredients may swap codes in one sheet.
 */
class ItemNamesSheetService
{
    public const array COLUMNS = ['ERP ID (do not change)', 'Code', 'Name', 'Unit', 'On hand in this store'];

    private const int CODE_MAX = 64;

    private const int NAME_MAX = 255;

    /**
     * The kinds of item a raw material store holds.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return [ItemType::RawMaterial->value, ItemType::Consumable->value];
    }

    /**
     * A workbook of every ingredient: id, code and name to edit, unit and
     * what this store holds to go by.
     */
    public function download(Warehouse $store): string
    {
        $items = $this->items();
        $onHand = StockBalance::query()
            ->where('warehouse_id', $store->id)
            ->selectRaw('item_id, SUM(on_hand) AS on_hand')
            ->groupBy('item_id')
            ->pluck('on_hand', 'item_id');

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Ingredients');
        $sheet->fromArray(self::COLUMNS, null, 'A1');
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->freezePane('A2');

        $row = 2;

        foreach ($items as $item) {
            $sheet->setCellValueExplicit("A{$row}", $item->id, DataType::TYPE_NUMERIC);
            $sheet->setCellValueExplicit("B{$row}", $item->code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("C{$row}", $item->name, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("D{$row}", (string) $item->stockUom?->code, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("E{$row}", Decimal::strip((string) ($onHand->get($item->id) ?? '0')), DataType::TYPE_STRING);
            $row++;
        }

        $last = max(2, $row - 1);
        // Codes stay text, so "007" is not turned into 7.
        $sheet->getStyle("B2:B{$last}")->getNumberFormat()->setFormatCode('@');
        // The columns not to edit are greyed.
        foreach (['A', 'D', 'E'] as $column) {
            $sheet->getStyle("{$column}1:{$column}{$last}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
        }

        foreach (['A' => 12, 'B' => 18, 'C' => 48, 'D' => 8, 'E' => 18] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $help = $book->createSheet();
        $help->setTitle('How to use');
        $help->fromArray([
            ['Change the Code and Name columns only.'],
            ['Leave the ERP ID as it is: it is how each row finds its ingredient, even after its code changes.'],
            ['Delete rows you do not want to change, or leave them as they are.'],
            ['Upload the sheet on the Stock screen. You will see every change before anything is saved.'],
        ], null, 'A1');
        $help->getColumnDimension('A')->setWidth(100);
        $book->setActiveSheetIndex(0);

        ob_start();
        (new Xlsx($book))->save('php://output');

        return (string) ob_get_clean();
    }

    /**
     * What an uploaded sheet would change, row by row, and what is wrong
     * with any row.
     *
     * @return array{changes: list<array{id: int, old_code: string, old_name: string, code: string, name: string, problem: string|null}>, unchanged: int, problems: list<string>}
     *
     * @throws ItemNamesSheetException
     */
    public function read(string $path): array
    {
        try {
            $rows = IOFactory::load($path)->getSheet(0)->toArray(null, true, false, false);
        } catch (Throwable) {
            throw new ItemNamesSheetException('The file could not be read as a spreadsheet. Upload the sheet downloaded from this screen, saved as .xlsx.');
        }

        $header = array_map(fn ($v) => strtolower(trim((string) $v)), $rows[0] ?? []);

        if (! str_starts_with($header[0] ?? '', 'erp id') || ($header[1] ?? '') !== 'code' || ($header[2] ?? '') !== 'name') {
            throw new ItemNamesSheetException('This is not the ingredient list from this screen: its first columns must be "ERP ID", "Code" and "Name". Download the list again and edit that.');
        }

        $items = $this->items()->keyBy('id');
        $proposed = [];
        $problems = [];
        $unchanged = 0;

        foreach (array_slice($rows, 1) as $i => $row) {
            $line = $i + 2;
            $idText = trim((string) ($row[0] ?? ''));
            $code = $this->clean($row[1] ?? '');
            $name = $this->clean($row[2] ?? '');

            if ($idText === '' && $code === '' && $name === '') {
                continue;
            }

            $item = ctype_digit($idText) ? $items->get((int) $idText) : null;

            if ($item === null) {
                $problems[] = "Row {$line}: the ERP ID \"{$idText}\" is not an ingredient. Keep the ERP ID column as downloaded.";

                continue;
            }

            if (isset($proposed[$item->id])) {
                $problems[] = "Row {$line}: {$item->code} is on the sheet twice; only its first row is used.";

                continue;
            }

            // Spacing alone is not a change: a name stored with a double
            // space is not offered back as one.
            if ($code === $this->clean($item->code) && $name === $this->clean($item->name)) {
                $unchanged++;

                continue;
            }

            $proposed[$item->id] = ['code' => $code, 'name' => $name];
        }

        $checked = $this->check($proposed);

        return [
            'changes' => collect($proposed)->map(fn (array $p, int $id) => [
                'id' => $id,
                'old_code' => $items[$id]->code,
                'old_name' => $items[$id]->name,
                'code' => $p['code'],
                'name' => $p['name'],
                'problem' => $checked[$id] ?? null,
            ])->sortBy([fn ($a, $b) => ($a['problem'] === null) <=> ($b['problem'] === null), fn ($a, $b) => strcmp($a['old_name'], $b['old_name'])])->values()->all(),
            'unchanged' => $unchanged,
            'problems' => $problems,
        ];
    }

    /**
     * Make the changes: all of them or, if any is wrong, none.
     *
     * @param  array<int, array{code: string, name: string}>  $changes  keyed by item id
     *
     * @throws ItemNamesSheetException
     */
    public function apply(array $changes, User $user): int
    {
        $changes = collect($changes)->map(fn (array $c) => ['code' => $this->clean($c['code']), 'name' => $this->clean($c['name'])])->all();

        return DB::transaction(function () use ($changes, $user): int {
            // Raw materials are changed as raw materials, as on their own edit
            // screen, so the audit trail files them in the same place.
            $ids = array_keys($changes);
            $items = RawMaterial::query()->whereKey($ids)->lockForUpdate()->get()
                ->concat(Item::query()->where('type', ItemType::Consumable->value)->whereKey($ids)->lockForUpdate()->get())
                ->keyBy('id');
            $problems = array_filter($this->check($changes));

            if ($problems !== [] || $items->count() !== count($changes)) {
                $first = $problems === [] ? 'An ingredient on the list is no longer there.' : reset($problems);

                throw new ItemNamesSheetException('Nothing was changed. '.$first.' Upload the sheet again to see where things stand.');
            }

            $changes = array_filter($changes, fn (array $c, int $id) => $c['code'] !== $items[$id]->code || $c['name'] !== $items[$id]->name, ARRAY_FILTER_USE_BOTH);
            $recoded = array_keys(array_filter($changes, fn (array $c, int $id) => $c['code'] !== $items[$id]->code, ARRAY_FILTER_USE_BOTH));

            // Codes are unique, so two ingredients swapping codes would trip
            // over each other: park the changing codes first.
            if ($recoded !== []) {
                DB::table('items')->whereIn('id', $recoded)->update(['code' => DB::raw("'~' || id::text")]);
            }

            foreach ($changes as $id => $change) {
                $item = $items[$id];
                $item->forceFill([...$change, 'updated_by' => $user->id])->save();
            }

            return count($changes);
        });
    }

    /**
     * What is wrong with each proposed change, keyed by item id.
     *
     * A change is wrong when its code or name is blank or too long, or when
     * its code would be the same as another ingredient's (or any other
     * item's, deleted ones included) once every right change is made.
     *
     * @param  array<int, array{code: string, name: string}>  $proposed
     * @return array<int, string|null>
     */
    private function check(array $proposed): array
    {
        $problems = [];

        foreach ($proposed as $id => $p) {
            $problems[$id] = match (true) {
                $p['code'] === '' => 'The code is blank.',
                $p['name'] === '' => 'The name is blank.',
                mb_strlen($p['code']) > self::CODE_MAX => 'The code is longer than '.self::CODE_MAX.' characters.',
                mb_strlen($p['name']) > self::NAME_MAX => 'The name is longer than '.self::NAME_MAX.' characters.',
                default => null,
            };
        }

        $codes = Item::withTrashed()->pluck('code', 'id')->all();
        $names = Item::withTrashed()->pluck('name', 'id')->all();

        // Settle the codes: a change that clashes is dropped, which can make
        // another one clash with the code left in place, so go round again.
        do {
            $final = $codes;

            foreach ($proposed as $id => $p) {
                if ($problems[$id] === null) {
                    $final[$id] = $p['code'];
                }
            }

            $holders = [];

            foreach ($final as $id => $code) {
                $holders[$code][] = $id;
            }

            $more = false;

            foreach ($proposed as $id => $p) {
                if ($problems[$id] !== null || $p['code'] === ($codes[$id] ?? null)) {
                    continue;
                }

                $others = array_values(array_diff($holders[$p['code']] ?? [], [$id]));

                if ($others !== []) {
                    $other = $others[0];
                    $problems[$id] = "The code {$p['code']} is already used by ".($names[$other] ?? 'another item').'.';
                    $more = true;
                }
            }
        } while ($more);

        return $problems;
    }

    /**
     * @return Collection<int, Item>
     */
    private function items(): Collection
    {
        return Item::query()->whereIn('type', self::types())
            ->with('stockUom:id,code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'type', 'stock_uom_id']);
    }

    private function clean(mixed $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
