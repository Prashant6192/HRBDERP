<?php

declare(strict_types=1);

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The online orders report as a workbook: one sheet per table on screen.
 */
final class OnlineOrdersReportExport implements Export, WithMultipleSheets
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(private readonly array $report) {}

    public function sheets(): array
    {
        $counts = ['Uploaded', 'To scan', 'Scanned (with courier)', 'Left behind', 'Cancelled', 'Returned'];
        $row = fn (array $r) => [$r['key'], $r['uploaded'], $r['to_scan'], $r['scanned'], $r['left_behind'], $r['cancelled'], $r['returned']];

        return [
            $this->sheet('By day', ['Day', ...$counts], [...array_map($row, $this->report['days']), $row($this->report['totals'])]),
            $this->sheet('By courier', ['Courier', ...$counts], array_map($row, $this->report['couriers'])),
            $this->sheet('By brand', ['Brand', ...$counts], array_map($row, $this->report['brands'])),
            $this->sheet('Products out', ['Code', 'Product', 'Pieces', 'Unit', 'Parcels'], array_map(
                fn (array $p) => [$p['code'], $p['name'], (float) $p['pieces'], $p['unit'], $p['parcels']],
                $this->report['products'],
            )),
            $this->sheet('Scanned by', ['Person', 'Parcels scanned', 'First scan', 'Last scan'], array_map(
                fn (array $s) => [$s['name'], $s['scanned'], $s['first'], $s['last']],
                $this->report['scanners'],
            )),
            $this->sheet('Cancelled & left behind', ['Day', 'AWB', 'Order', 'Courier', 'Brand', 'What', 'Reason', 'By', 'At', 'Stock put back'], array_map(
                fn (array $p) => [$p['date'], $p['awb'], $p['order'], $p['courier'], $p['brand'], $p['what'], $p['reason'], $p['by'], $p['at'], $p['stock_back'] ? 'Yes' : ''],
                $this->report['look_again'],
            )),
        ];
    }

    /**
     * @param  list<string>  $headings
     * @param  list<array<int, mixed>>  $rows
     */
    private function sheet(string $title, array $headings, array $rows): object
    {
        return new class($title, $headings, $rows) implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
        {
            /**
             * @param  list<string>  $headings
             * @param  list<array<int, mixed>>  $rows
             */
            public function __construct(private readonly string $title, private readonly array $headings, private readonly array $rows) {}

            public function array(): array
            {
                return $this->rows;
            }

            public function headings(): array
            {
                return $this->headings;
            }

            public function title(): string
            {
                return $this->title;
            }
        };
    }
}
