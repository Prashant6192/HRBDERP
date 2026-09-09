<?php

declare(strict_types=1);

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes small workbooks in the two layouts chemists hand us, with made-up
 * ingredients. The real formulation workbook is never committed as a fixture.
 */
trait BuildsFormulationWorkbooks
{
    /**
     * @param  array<string, list<list<mixed>>>  $sheets  Sheet title => rows of cells.
     */
    protected function writeWorkbook(array $sheets): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        foreach ($sheets as $title => $rows) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($title);

            foreach ($rows as $r => $row) {
                foreach ($row as $c => $value) {
                    if ($value !== null) {
                        $sheet->setCellValue([$c + 1, $r + 1], $value);
                    }
                }
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'formulas-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Column layout: INCI | trade name | %, product name in the first column.
     *
     * @return list<list<mixed>>
     */
    protected function tabularSheet(): array
    {
        return [
            ['Test Clarifying', null, null, null],
            ['Face Wash', 'Ingredients', 'Ingredients', '%w/w'],
            [null, 'Test Surfactant A', 'SURF-A', 15.0],
            [null, 'Test Humectant B', 'Test Humectant B', 5.5],
            [null, 'Test Alkali C', 'Test Alkali C', null],   // as required
            [null, 'Test Colour D', 'Colourant D', 0.001],
        ];
    }

    /**
     * One-cell-per-line layout with wrapped text, a QS line, a supplier
     * name leaking into the column, and a line without a grade.
     *
     * @return list<list<mixed>>
     */
    protected function verticalSheet(): array
    {
        return [
            ['Test Strengthenin', null],
            ['g Shampoo', null],
            [null, null],
            [null, 'Purified Water'],
            [null, 'IP'],
            [null, 'QS to 100 ml'],
            [null, 'Solvent'],
            [null, 'Moon'],
            [null, 'Enterprises'],
            [null, 'Test Surfactant A'],
            [null, 'IH'],
            [null, 12.0],
            [null, 'Cleaning'],
            [null, 'Test Extract (Long Name) Leaf'],
            [null, 'Extract'],
            [null, 'IH'],
            [null, 2.0],
            [null, 'Skin'],
            [null, 'conditioning'],
            [null, 'Test Thickener E'],
            [null, 0.5],
            [null, 'Gelling'],
        ];
    }
}
