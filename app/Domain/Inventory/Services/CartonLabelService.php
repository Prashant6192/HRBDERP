<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\CartonLabelPrint;
use App\Domain\Inventory\Models\InventoryLot;
use App\Domain\Manufacturing\Models\ManufacturingOrder;
use App\Domain\Marketplace\Models\Brand;
use App\Domain\MasterData\Models\Item;
use App\Domain\Warehousing\Models\Facility;
use App\Support\Scanning\Qr;
use App\Support\Scanning\ScanCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use InvalidArgumentException;

/**
 * The sticker on every outer carton of a finished batch.
 *
 * Nothing on it is typed twice. The product master gives the name, net
 * content, MRP and brand; the batch gives its number and dates; the carton
 * plan gives the pieces per carton and the gross weight; the factory's
 * record gives the manufacturer's name, address and licence; the brand
 * gives the "Marketed by" line and consumer care. What a wholesale pack
 * must declare is worked out from those: the number of retail packs, the
 * net quantity of the carton, and the MRP of the carton inclusive of all
 * taxes. Each carton carries a QR with its batch, box number and pieces,
 * which is what the depot scans to receive it.
 *
 * The same facts come out three ways: a TSPL program for a TSC label
 * printer (100 × 150 mm), a 100 × 150 mm PDF for the printer's own driver,
 * and the A5 sheet kept for the office printer.
 */
class CartonLabelService
{
    public const string FORMAT_TSPL = 'tspl';

    public const string FORMAT_STICKER_PDF = 'sticker_pdf';

    public const string FORMAT_A5 = 'a5';

    /** 203 dpi: eight dots to the millimetre. */
    private const int DOTS_PER_MM = 8;

    /**
     * @param  array{boxes: int, units_per_box: int, gross_weight_kg: string|null, start_box: int, net_quantity: string|null, remarks: string|null}  $plan
     */
    public function plan(InventoryLot $lot, array $plan, int $userId): InventoryLot
    {
        if ($plan['boxes'] < 1 || $plan['boxes'] > 5000) {
            throw new InvalidArgumentException('Enter between 1 and 5000 boxes.');
        }

        $lot->forceFill(['carton_plan' => [
            'boxes' => (int) $plan['boxes'],
            'units_per_box' => (int) $plan['units_per_box'],
            'gross_weight_kg' => $plan['gross_weight_kg'] !== null && $plan['gross_weight_kg'] !== '' ? (string) $plan['gross_weight_kg'] : null,
            'start_box' => (int) ($plan['start_box'] ?? 1),
            'net_quantity' => $plan['net_quantity'] ?? null,
            'remarks' => $plan['remarks'] ?? null,
            'planned_by' => $userId,
            'planned_at' => now()->toIso8601String(),
        ]])->save();

        // Remembered on the product, so the next batch starts from it.
        Item::query()->whereKey($lot->item_id)->update(['units_per_carton' => (int) $plan['units_per_box']]);

        return $lot;
    }

    /**
     * Everything printed on the carton, and one entry per box.
     *
     * @return array<string, mixed>
     */
    public function sticker(InventoryLot $lot): array
    {
        if (! $lot->qc_status->isReleasable()) {
            throw new InvalidArgumentException("Batch {$lot->batch_number} has not been released by quality; carton labels cannot be printed.");
        }

        $plan = $lot->carton_plan;

        if (! is_array($plan) || empty($plan['boxes']) || empty($plan['units_per_box'])) {
            throw new InvalidArgumentException('Record how the batch was boxed before printing carton labels.');
        }

        $lot->loadMissing(['item.netContentUom']);
        $item = $lot->item;
        $units = (int) $plan['units_per_box'];
        $factory = $this->factoryOf($lot);
        $brand = $this->brandOf($item);

        $netPerUnit = $plan['net_quantity'] ?? null;

        if (($netPerUnit === null || $netPerUnit === '') && $item->net_content !== null) {
            $netPerUnit = $this->trim((float) $item->net_content).' '.strtolower((string) $item->netContentUom?->code);
        }

        $mrp = $item->mrp !== null ? (float) $item->mrp : null;
        $start = (int) ($plan['start_box'] ?? 1);
        $last = $start + (int) $plan['boxes'] - 1;
        $boxes = [];

        for ($n = $start; $n <= $last; $n++) {
            $code = ScanCode::carton($lot->batch_number, $n, $units);
            $boxes[] = ['no' => $n, 'code' => $code, 'url' => ScanCode::url($code)];
        }

        $address = $factory ? implode(', ', array_filter([
            $factory->address_line_1, $factory->address_line_2, $factory->city,
            trim(($factory->state ?? '').' '.($factory->pincode ?? '')),
        ])) : null;

        return [
            'brand' => $brand?->name ?? ($item->brand ?: (string) config('erp.company.brand')),
            'product' => $item->name,
            'code' => $item->code,
            'units' => $units,
            'net_per_unit' => $netPerUnit ?: null,
            'net_carton' => $this->netCarton($item, $units),
            'batch' => $lot->batch_number,
            'mfg' => $lot->manufactured_at?->format('M Y'),
            'expiry' => $lot->expiry_at?->format('M Y'),
            'mrp_unit' => $mrp !== null ? number_format($mrp, 2) : null,
            'mrp_carton' => $mrp !== null ? number_format($mrp * $units, 2) : null,
            'gross_weight' => ! empty($plan['gross_weight_kg']) ? $this->trim((float) $plan['gross_weight_kg']).' kg' : null,
            'licence' => $factory?->manufacturing_licence,
            'manufacturer' => $factory ? trim(($factory->legal_name ?: config('erp.company.name')).($address ? ', '.$address : '')) : (string) config('erp.company.name'),
            'marketed_by' => $brand?->marketed_by,
            'consumer_care' => $brand?->consumer_care,
            'barcode' => $item->barcode,
            'remarks' => $plan['remarks'] ?? null,
            'first_box' => $start,
            'last_box' => $last,
            'boxes' => $boxes,
        ];
    }

    /**
     * A TSPL program for a TSC printer: one 100 × 150 mm sticker per box.
     */
    public function tspl(InventoryLot $lot, ?int $from = null, ?int $to = null, int $copies = 1): string
    {
        $s = $this->sticker($lot);
        [$from, $to] = $this->range($s, $from, $to);
        $copies = max(1, min(10, $copies));
        $w = 100 * self::DOTS_PER_MM;
        $x = 24;
        $inner = $w - 2 * $x;

        $out = [
            'SIZE 100 mm,150 mm',
            'GAP 3 mm,0 mm',
            'DIRECTION 1',
            'REFERENCE 0,0',
            'CODEPAGE 1252',
            'DENSITY 10',
            'SPEED 4',
        ];

        foreach ($s['boxes'] as $box) {
            if ($box['no'] < $from || $box['no'] > $to) {
                continue;
            }

            $y = 24;
            $out[] = 'CLS';
            $out[] = $this->text($x, $y, '3', strtoupper($s['brand']));
            $y += 36;

            foreach ($this->wrap($s['product'], 30, 2) as $line) {
                $out[] = $this->text($x, $y, '4', $line);
                $y += 40;
            }

            $out[] = $this->text($x, $y, '3', trim($s['units'].' pcs'.($s['net_per_unit'] ? ' x '.$s['net_per_unit'] : '').($s['net_carton'] ? ' | Net '.$s['net_carton'] : '')));
            $y += 36;
            $out[] = "BAR {$x},{$y},{$inner},3";
            $y += 14;

            foreach ([
                ['Batch No.', $s['batch']],
                ['Mfg.', $s['mfg'] ?? '-'],
                ['Use before', $s['expiry'] ?? '-'],
                ['Gross wt.', $s['gross_weight'] ?? '-'],
                ['Lic. No.', $s['licence'] ?? '-'],
            ] as [$k, $v]) {
                $out[] = $this->text($x, $y, '3', $k);
                $out[] = $this->text($x + 200, $y, '3', (string) $v);
                $y += 32;
            }

            $y += 8;
            $half = intdiv($inner - 16, 2);
            $out[] = "BOX {$x},{$y},".($x + $half).','.($y + 112).',3';
            $out[] = 'BOX '.($x + $half + 16).",{$y},".($x + $inner).','.($y + 112).',3';
            $out[] = $this->text($x + 12, $y + 10, '2', 'MRP per piece');
            $out[] = $this->text($x + 12, $y + 32, '1', '(incl. of all taxes)');
            $out[] = $this->text($x + 12, $y + 58, '4', $s['mrp_unit'] ? 'Rs. '.$s['mrp_unit'] : '-');
            $out[] = $this->text($x + $half + 28, $y + 10, '2', "MRP of carton, {$s['units']} pcs");
            $out[] = $this->text($x + $half + 28, $y + 32, '1', '(incl. of all taxes)');
            $out[] = $this->text($x + $half + 28, $y + 58, '4', $s['mrp_carton'] ? 'Rs. '.$s['mrp_carton'] : '-');
            $y += 128;

            foreach ($this->wrap('Mfd. by: '.$s['manufacturer'], 62, 3) as $line) {
                $out[] = $this->text($x, $y, '2', $line);
                $y += 24;
            }

            if ($s['marketed_by']) {
                foreach ($this->wrap($s['marketed_by'], 62, 2) as $line) {
                    $out[] = $this->text($x, $y, '2', $line);
                    $y += 24;
                }
            }

            if ($s['consumer_care']) {
                $out[] = $this->text($x, $y, '2', 'Consumer care: '.$this->ascii($s['consumer_care']));
                $y += 24;
            }

            if ($s['remarks']) {
                $out[] = $this->text($x, $y, '2', $s['remarks']);
                $y += 24;
            }

            // The carton's QR and its number, at the foot of the sticker.
            $qrY = max($y + 16, 1200 - 24 - 250);
            $out[] = "QRCODE {$x},{$qrY},M,6,A,0,\"".$this->quote($box['url']).'"';
            $out[] = $this->text($x + 290, $qrY + 4, '2', 'CARTON');
            $out[] = $this->text($x + 290, $qrY + 30, '5', sprintf('%02d', $box['no']), 2, 2);
            $out[] = $this->text($x + 290, $qrY + 136, '3', 'of '.$s['last_box']);
            $out[] = $this->text($x + 290, $qrY + 176, '1', $box['code']);

            if ($s['barcode'] && preg_match('/^\d{13}$/', (string) $s['barcode']) === 1) {
                $out[] = 'BARCODE '.($x + 290).','.($qrY + 196).',"EAN13",40,1,0,2,2,"'.$s['barcode'].'"';
            }

            $out[] = "PRINT 1,{$copies}";
        }

        return implode("\r\n", $out)."\r\n";
    }

    /**
     * The sticker as a PDF: 100 × 150 mm for the TSC driver, or A5 sheets.
     */
    public function render(InventoryLot $lot, string $format = self::FORMAT_A5, ?int $from = null, ?int $to = null): PdfDocument
    {
        $s = $this->sticker($lot);
        [$from, $to] = $this->range($s, $from, $to);
        $boxes = array_values(array_filter($s['boxes'], fn (array $b) => $b['no'] >= $from && $b['no'] <= $to));

        foreach ($boxes as $i => $box) {
            $boxes[$i]['qr'] = Qr::dataUri($box['url'], 300);
        }

        $pdf = Pdf::loadView($format === self::FORMAT_STICKER_PDF ? 'pdf.carton-sticker' : 'pdf.carton-labels', [
            's' => $s,
            'boxes' => $boxes,
            'lot' => $lot,
        ]);

        // 100 × 150 mm is 283.46 × 425.2 points.
        return $format === self::FORMAT_STICKER_PDF
            ? $pdf->setPaper([0, 0, 283.46, 425.2], 'portrait')
            : $pdf->setPaper('a5', 'landscape');
    }

    /**
     * Write down a print: who, when, which printer, which cartons.
     */
    public function logPrint(InventoryLot $lot, string $format, ?string $printer, int $from, int $to, int $copies, int $userId): CartonLabelPrint
    {
        return CartonLabelPrint::create([
            'lot_id' => $lot->id,
            'format' => $format,
            'printer' => $printer !== null ? mb_substr($printer, 0, 160) : null,
            'first_box' => $from,
            'last_box' => $to,
            'copies' => max(1, min(10, $copies)),
            'printed_by' => $userId,
            'printed_at' => now(),
        ]);
    }

    public function filename(InventoryLot $lot, string $format = self::FORMAT_A5): string
    {
        return ($format === self::FORMAT_STICKER_PDF ? 'carton-stickers-' : 'cartons-').$lot->batch_number.'.pdf';
    }

    /**
     * @param  array<string, mixed>  $sticker
     * @return array{0: int, 1: int}
     */
    public function range(array $sticker, ?int $from, ?int $to): array
    {
        $first = (int) $sticker['first_box'];
        $last = (int) $sticker['last_box'];
        $from = $from === null ? $first : max($first, min($last, $from));
        $to = $to === null ? $last : max($from, min($last, $to));

        return [$from, $to];
    }

    /**
     * Where the batch was made: its manufacturing order's factory, else
     * the company's manufacturing facility.
     */
    private function factoryOf(InventoryLot $lot): ?Facility
    {
        $order = ManufacturingOrder::query()->where('output_lot_id', $lot->id)->with('facility')->first();

        return $order?->facility ?? Facility::query()->where('can_manufacture', true)->orderBy('id')->first();
    }

    private function brandOf(Item $item): ?Brand
    {
        $name = trim((string) $item->brand);

        $brand = $name === '' ? null : Brand::query()
            ->whereRaw('lower(name) = ?', [strtolower($name)])
            ->orWhereRaw('lower(code) = ?', [strtolower($name)])
            ->first();

        return $brand ?? Brand::query()->whereRaw('lower(name) = ?', [strtolower((string) config('erp.company.brand'))])->first();
    }

    private function netCarton(Item $item, int $units): ?string
    {
        if ($item->net_content === null || $item->netContentUom === null) {
            return null;
        }

        $total = (float) $item->net_content * $units;
        $uom = strtoupper($item->netContentUom->code);

        return match (true) {
            $uom === 'ML' && $total >= 1000 => $this->trim($total / 1000).' L',
            $uom === 'G' && $total >= 1000 => $this->trim($total / 1000).' kg',
            default => $this->trim($total).' '.strtolower($uom),
        };
    }

    private function trim(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    private function text(int $x, int $y, string $font, string $text, int $xm = 1, int $ym = 1): string
    {
        return "TEXT {$x},{$y},\"{$font}\",0,{$xm},{$ym},\"".$this->quote($this->ascii($text)).'"';
    }

    /**
     * The printer's built-in fonts are plain ASCII: the rupee sign and
     * curly quotes are spelt out, anything else becomes a question mark.
     */
    private function ascii(string $text): string
    {
        $text = strtr($text, ['₹' => 'Rs.', '’' => "'", '‘' => "'", '“' => "'", '”' => "'", '–' => '-', '—' => '-', '·' => '|', '×' => 'x']);

        return preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
    }

    private function quote(string $text): string
    {
        return str_replace('"', "'", $text);
    }

    /**
     * @return list<string>
     */
    private function wrap(string $text, int $width, int $maxLines): array
    {
        $lines = explode("\n", wordwrap($this->ascii($text), $width, "\n", true));

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $lines[$maxLines - 1] = rtrim(mb_substr($lines[$maxLines - 1], 0, $width - 3)).'...';
        }

        return $lines;
    }
}
