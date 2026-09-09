<?php

declare(strict_types=1);

namespace App\Domain\Formulation\Services;

use App\Domain\Formulation\DTOs\ParsedFormula;
use App\Domain\Formulation\DTOs\ParsedIngredient;
use App\Domain\Formulation\DTOs\ParsedWorkbook;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads formulation workbooks as chemists actually write them.
 *
 * Two layouts are understood, one sheet per product:
 *
 *  Tabular — a header row ("Ingredients | Ingredients | %w/w") followed by
 *  one ingredient per row: INCI name, trade name, percentage. The product
 *  name sits in the first column above and beside the header.
 *
 *  Vertical — a single column read top to bottom, each ingredient being a
 *  run of cells: name (possibly wrapped over several cells), an optional
 *  grade (IH, IP …), the percentage or "QS to 100 ml", then its function
 *  (also possibly wrapped). The product name sits in the first column above.
 *
 * Anything the parser had to guess at — a name it re-joined, a supplier
 * name it dropped, a missing amount — becomes a warning for the person
 * confirming the import rather than a silent decision.
 */
class FormulationSheetParser
{
    private const string GRADE_PATTERN = '/^(IH|IP|BP|USP|EP|COS|FOOD)$/i';

    private const string QS_PATTERN = '/^q\.?\s*s\.?(\s|$|\.)/i';

    private const string UNIT_PATTERN = '/^(g|gm|gms|gram|grams|kg|ml|l|ltr|litre|liter)$/i';

    /**
     * Cells that are not ingredients but turn up in the ingredient column —
     * a supplier name next to the water line, for example.
     */
    private const array NOISE = ['moon', 'enterprises', 'moon enterprises', 'supplier', 'vendor'];

    public function parseFile(string $path): ParsedWorkbook
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Workbook not found at {$path}.");
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $workbook = new ParsedWorkbook;

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            /** @var Worksheet $sheet */
            $rows = $sheet->toArray(null, true, false, false);
            $parsed = $this->parseRows($sheet->getTitle(), $rows);

            if ($parsed === null) {
                $workbook->skippedSheets[] = $sheet->getTitle();
            } else {
                $workbook->formulas[] = $parsed;
            }
        }

        $spreadsheet->disconnectWorksheets();

        return $workbook;
    }

    /**
     * @param  list<list<mixed>>  $rows  Raw cell values, row-major.
     */
    public function parseRows(string $sheetName, array $rows): ?ParsedFormula
    {
        $rows = array_map(fn (array $row): array => array_map($this->clean(...), $row), $rows);

        if (! $this->hasContent($rows)) {
            return null;
        }

        $header = $this->findHeaderRow($rows);

        $formula = $header === null
            ? $this->parseVertical($sheetName, $rows)
            : $this->parseTabular($sheetName, $rows, $header);

        if ($formula === null || $formula->ingredients === []) {
            return null;
        }

        $this->checkTotals($formula);

        return $formula;
    }

    // ---- Tabular ---------------------------------------------------------

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function parseTabular(string $sheetName, array $rows, int $headerIndex): ?ParsedFormula
    {
        $headerCells = $rows[$headerIndex];

        $percentColumn = null;
        $nameColumns = [];

        foreach ($headerCells as $column => $cell) {
            if (! is_string($cell) || $cell === '') {
                continue;
            }

            if (str_contains($cell, '%') || preg_match('/percent/i', $cell)) {
                $percentColumn = $column;
            } elseif (preg_match('/ingredient|inci|material|trade|name/i', $cell)) {
                $nameColumns[] = $column;
            }
        }

        if ($percentColumn === null || $nameColumns === []) {
            return null;
        }

        // The product name: first-column cells above and on the header row.
        $titleFragments = [];

        for ($r = 0; $r <= $headerIndex; $r++) {
            $cell = $rows[$r][0] ?? null;

            if (is_string($cell) && $cell !== '' && ! in_array(0, $nameColumns, strict: true)) {
                $titleFragments[] = $cell;
            }
        }

        $formula = new ParsedFormula(
            sheet: $sheetName,
            name: $this->joinFragments($titleFragments) ?: $sheetName,
            layout: 'tabular',
        );

        if (count($titleFragments) > 1) {
            $formula->warnings[] = "Product name was joined from wrapped cells: \"{$formula->name}\".";
        }

        $lineNo = 0;

        for ($r = $headerIndex + 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            $names = [];

            foreach ($nameColumns as $column) {
                $value = $row[$column] ?? null;

                if (is_string($value) && $value !== '') {
                    $names[] = $value;
                }
            }

            if ($names === []) {
                continue;
            }

            $inci = $names[0];
            $trade = $names[1] ?? null;
            $amount = $row[$percentColumn] ?? null;

            $ingredient = new ParsedIngredient(
                lineNo: ++$lineNo,
                name: $inci,
                inciName: $inci,
                tradeName: $trade !== null && strcasecmp($trade, $inci) !== 0 ? $trade : null,
            );

            $this->applyAmount($ingredient, $amount, $formula);

            $formula->ingredients[] = $ingredient;
        }

        return $formula;
    }

    // ---- Vertical --------------------------------------------------------

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function parseVertical(string $sheetName, array $rows): ?ParsedFormula
    {
        // The ingredient column is whichever holds the most cells; the
        // product name is what sits in the first column above it.
        $counts = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                if ($cell !== null && $cell !== '') {
                    $counts[$column] = ($counts[$column] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $dataColumn = (int) array_key_first($counts);

        $firstDataRow = null;

        foreach ($rows as $index => $row) {
            if (($row[$dataColumn] ?? null) !== null && $row[$dataColumn] !== '') {
                $firstDataRow = $index;
                break;
            }
        }

        $titleFragments = [];
        $stream = [];

        foreach ($rows as $index => $row) {
            foreach ($row as $column => $cell) {
                if ($cell === null || $cell === '') {
                    continue;
                }

                $isTitle = $column !== $dataColumn
                    ? ($firstDataRow === null || $index < $firstDataRow || $column < $dataColumn)
                    : false;

                // A sheet whose title shares the data column: the leading
                // text cells before the first grade/amount are the title only
                // when there is nothing in an earlier column to be one.
                if ($isTitle) {
                    if (is_string($cell)) {
                        $titleFragments[] = $cell;
                    }
                } else {
                    $stream[] = $cell;
                }
            }
        }

        if ($titleFragments === [] && $firstDataRow !== null) {
            // Title in the same column as the data: peel off leading text
            // cells up to the first ingredient block, which we recognise by
            // the grade or amount that follows a name.
            $titleFragments = $this->peelTitle($stream);
        }

        $formula = new ParsedFormula(
            sheet: $sheetName,
            name: $this->joinFragments($titleFragments) ?: $sheetName,
            layout: 'vertical',
        );

        if (count($titleFragments) > 1) {
            $formula->warnings[] = "Product name was joined from wrapped cells: \"{$formula->name}\".";
        }

        $this->walkStream($formula, $stream);

        return $formula;
    }

    /**
     * @param  list<mixed>  $stream
     */
    private function walkStream(ParsedFormula $formula, array $stream): void
    {
        $state = 'name';
        $nameFragments = [];
        $purposeFragments = [];
        $current = null;
        $lineNo = 0;

        $finish = function () use (&$current, &$nameFragments, &$purposeFragments, $formula, &$lineNo): void {
            if ($current === null) {
                return;
            }

            $current->name = $this->joinFragments($nameFragments);
            $current->inciName = $current->name;
            $current->purpose = $purposeFragments === [] ? null : $this->joinFragments($purposeFragments, alwaysSpace: true);

            if (count($nameFragments) > 1) {
                $current->warnings[] = "Name was joined from wrapped cells: \"{$current->name}\".";
            }

            if ($current->name === '') {
                $formula->warnings[] = "An amount ({$current->percentage}%) appeared with no ingredient name and was skipped.";
            } else {
                $current->lineNo = ++$lineNo;
                $formula->ingredients[] = $current;
            }

            $current = null;
            $nameFragments = [];
            $purposeFragments = [];
        };

        $begin = function (mixed $firstFragment) use (&$current, &$nameFragments): void {
            $current = new ParsedIngredient(lineNo: 0, name: '');
            $nameFragments = $firstFragment === null ? [] : [$firstFragment];
        };

        foreach ($stream as $cell) {
            $kind = $this->classify($cell);

            if ($kind === 'noise') {
                $formula->warnings[] = "Ignored \"{$cell}\" in the ingredient list (looks like a supplier name).";

                continue;
            }

            switch ($state) {
                case 'name':
                    if ($kind === 'text') {
                        if ($current === null) {
                            $begin($cell);
                        } else {
                            $nameFragments[] = $cell;
                        }
                    } elseif ($kind === 'grade') {
                        if ($current === null) {
                            $begin(null);
                        }
                        $current->grade = strtoupper((string) $cell);
                        $state = 'amount';
                    } else {
                        if ($current === null) {
                            $begin(null);
                        }
                        $this->applyAmount($current, $cell, $formula);
                        $state = $kind === 'qs' && $current->qsNote !== null && ! preg_match('/[a-z]/i', substr($current->qsNote, -3)) ? 'qs_unit' : 'purpose';
                    }
                    break;

                case 'amount':
                    if ($kind === 'percent' || $kind === 'qs') {
                        $this->applyAmount($current, $cell, $formula);
                        $state = $kind === 'qs' && ! $this->qsHasUnit($current) ? 'qs_unit' : 'purpose';
                    } elseif ($kind === 'text') {
                        // Grade but no amount: treat as "as required" and read
                        // this cell as the function.
                        $current->warnings[] = 'No amount was given; treated as "as required".';
                        $purposeFragments[] = $cell;
                        $state = 'purpose_more';
                    }
                    break;

                case 'qs_unit':
                    if ($kind === 'unit') {
                        $current->qsNote = trim($current->qsNote.' '.$cell);
                        $this->applyBatchBasis($formula, $current->qsNote);
                        $state = 'purpose';
                        break;
                    }
                    $state = 'purpose';
                    // no break — the cell is the function.

                case 'purpose':
                    if ($kind === 'text') {
                        $purposeFragments[] = $cell;
                        $state = 'purpose_more';
                    } elseif ($kind === 'grade' || $kind === 'percent' || $kind === 'qs') {
                        // An amount right after an amount: the previous line
                        // has no function and this begins a nameless line.
                        $finish();
                        $begin(null);
                        if ($kind === 'grade') {
                            $current->grade = strtoupper((string) $cell);
                            $state = 'amount';
                        } else {
                            $this->applyAmount($current, $cell, $formula);
                            $state = 'purpose';
                        }
                    }
                    break;

                case 'purpose_more':
                    if ($kind === 'text' && $this->looksLikeContinuation($cell)) {
                        $purposeFragments[] = $cell;
                    } elseif ($kind === 'text') {
                        $finish();
                        $begin($cell);
                        $state = 'name';
                    } else {
                        $finish();
                        $begin(null);
                        if ($kind === 'grade') {
                            $current->grade = strtoupper((string) $cell);
                            $state = 'amount';
                        } else {
                            $this->applyAmount($current, $cell, $formula);
                            $state = 'purpose';
                        }
                    }
                    break;
            }
        }

        $finish();
    }

    /**
     * Leading text cells before the first grade/amount are the product name
     * when the sheet keeps everything in one column — but only the cells
     * before the last run of text, which is the first ingredient's name.
     *
     * @param  list<mixed>  $stream
     * @return list<string>
     */
    private function peelTitle(array &$stream): array
    {
        $leading = [];

        foreach ($stream as $cell) {
            if ($this->classify($cell) !== 'text') {
                break;
            }

            $leading[] = $cell;
        }

        // Nothing to split: the first ingredient starts immediately.
        if (count($leading) < 2) {
            return [];
        }

        // Take everything but the last text cell as the title; the last one
        // is the first ingredient's name. Not perfect for wrapped ingredient
        // names, which is why the join is reported as a warning.
        $title = array_slice($leading, 0, -1);
        array_splice($stream, 0, count($title));

        return $title;
    }

    // ---- Shared helpers --------------------------------------------------

    private function classify(mixed $cell): string
    {
        if (is_int($cell) || is_float($cell)) {
            return 'percent';
        }

        $text = trim((string) $cell);

        if ($text === '') {
            return 'empty';
        }

        if (is_numeric($text)) {
            return 'percent';
        }

        if (preg_match(self::GRADE_PATTERN, $text)) {
            return 'grade';
        }

        if (preg_match(self::QS_PATTERN, $text)) {
            return 'qs';
        }

        if (preg_match(self::UNIT_PATTERN, $text)) {
            return 'unit';
        }

        if (in_array(strtolower($text), self::NOISE, strict: true)) {
            return 'noise';
        }

        return 'text';
    }

    private function applyAmount(ParsedIngredient $ingredient, mixed $amount, ParsedFormula $formula): void
    {
        $kind = $this->classify($amount);

        if ($kind === 'percent') {
            $ingredient->percentage = $this->decimal($amount);
            $ingredient->isQs = false;

            return;
        }

        if ($kind === 'qs') {
            $ingredient->isQs = true;
            $ingredient->percentage = null;
            $ingredient->qsNote = trim((string) $amount);
            $this->applyBatchBasis($formula, $ingredient->qsNote);

            return;
        }

        $ingredient->percentage = null;
        $ingredient->isQs = false;
        $ingredient->warnings[] = 'No amount was given; treated as "as required" (dosed at the kettle, not planned).';
    }

    /**
     * "QS to 100 ml" tells us the batch the percentages describe.
     */
    private function applyBatchBasis(ParsedFormula $formula, string $qsNote): void
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(ml|gm|gms|g|kg|l|ltr|litre|liter)?\b/i', $qsNote, $m)) {
            $formula->batchSize = $this->decimal($m[1]);

            if (isset($m[2]) && $m[2] !== '') {
                $formula->batchUomCode = match (strtolower($m[2])) {
                    'ml' => 'ML',
                    'l', 'ltr', 'litre', 'liter' => 'L',
                    'kg' => 'KG',
                    default => 'G',
                };
            }
        }
    }

    private function qsHasUnit(ParsedIngredient $ingredient): bool
    {
        return $ingredient->qsNote !== null && (bool) preg_match('/\d\s*(ml|gm|gms|g|kg|l|ltr|litre|liter)\b/i', $ingredient->qsNote);
    }

    private function checkTotals(ParsedFormula $formula): void
    {
        $total = $formula->totalPercentage();

        if ($total->isGreaterThan(100)) {
            $formula->warnings[] = "Percentages add up to {$total}%, which is more than 100%.";
        } elseif (! $formula->hasQs() && $total->isLessThan(100)) {
            $remaining = BigDecimal::of(100)->minus($total);
            $formula->warnings[] = "Percentages add up to {$total}% with no QS line; {$remaining}% is unaccounted for (usually water).";
        }
    }

    /**
     * A wrapped cell continues the previous one when it starts in lower case.
     */
    private function looksLikeContinuation(string $cell): bool
    {
        return (bool) preg_match('/^\p{Ll}/u', $cell);
    }

    /**
     * Re-join text that a spreadsheet wrapped across cells.
     *
     * A fragment beginning in lower case directly after a letter is the rest
     * of a broken word ("Strengthenin" + "g Shampoo"); otherwise the pieces
     * are separate words.
     *
     * @param  list<string>  $fragments
     */
    private function joinFragments(array $fragments, bool $alwaysSpace = false): string
    {
        $result = '';

        foreach ($fragments as $fragment) {
            $fragment = trim($fragment);

            if ($fragment === '') {
                continue;
            }

            if ($result === '') {
                $result = $fragment;

                continue;
            }

            $brokenWord = preg_match('/\p{L}$/u', $result) && preg_match('/^\p{Ll}/u', $fragment);
            $glue = (! $alwaysSpace && ($brokenWord || preg_match('#[-/]$#', $result))) ? '' : ' ';
            $result .= $glue.$fragment;
        }

        return preg_replace('/\s+/', ' ', $result) ?? $result;
    }

    private function clean(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        // A control character (Excel writes it as _x0002_) is what survives
        // of a non-breaking hyphen the author typed; treat it as a hyphen.
        $text = preg_replace('/_x[0-9A-Fa-f]{4}_/', '-', $text) ?? $text;
        $text = str_replace(["\u{00A0}", "\r", "\n", "\t"], ' ', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/', '-', $text) ?? $text;
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return $text === '' ? null : $text;
    }

    private function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

            return $text === '' || $text === '-' ? '0' : $text;
        }

        $text = trim((string) $value);

        return rtrim(rtrim(str_contains($text, '.') ? $text : $text.'.0', '0'), '.') ?: '0';
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function findHeaderRow(array $rows): ?int
    {
        foreach ($rows as $index => $row) {
            $hasPercent = false;
            $hasName = false;

            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    continue;
                }

                if (str_contains($cell, '%') || preg_match('/^percent/i', $cell)) {
                    $hasPercent = true;
                } elseif (preg_match('/ingredient|inci|material|trade/i', $cell)) {
                    $hasName = true;
                }
            }

            if ($hasPercent && $hasName) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function hasContent(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if ($cell !== null && $cell !== '') {
                    return true;
                }
            }
        }

        return false;
    }
}
