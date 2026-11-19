<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* One 4 × 6 inch (101.6 × 152.4 mm) label per page, everything at a fixed place.
           DejaVu Sans carries the rupee sign. */
        @page { margin: 0; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #000; }
        .label { position: relative; width: 101.6mm; height: 152.4mm; overflow: hidden; page-break-after: always; }
        .label.last { page-break-after: auto; }
        .a { position: absolute; left: 8mm; right: 8mm; }
        .brand { top: 7mm; font-size: 22pt; font-weight: bold; border-bottom: 0.6mm solid #000; padding-bottom: 1.5mm; }
        .name { top: 20.5mm; font-size: 11pt; font-weight: bold; line-height: 1.2; height: 19mm; overflow: hidden; }
        table { position: absolute; top: 40.5mm; left: 8mm; width: 85.6mm; border-collapse: collapse; font-size: 10.5pt; }
        td { border: 0.3mm solid #000; padding: 1.1mm 2mm; }
        td.k { width: 30mm; font-weight: bold; }
        .units { top: 95mm; font-size: 17pt; font-weight: bold; text-align: center; border: 0.6mm solid #000; padding: 1.5mm 0; }
        .bar { position: absolute; top: 112mm; height: 24mm; background: #000; }
        .upc { top: 137.5mm; font-size: 13pt; text-align: center; letter-spacing: 1pt; }
    </style>
</head>
<body>
@for ($i = 0; $i < $copies; $i++)
    <div class="label{{ $i === $copies - 1 ? ' last' : '' }}">
        <div class="a brand">{{ $product->brand_name }}</div>
        <div class="a name">{{ \Illuminate\Support\Str::limit($product->product_name, 110) }}</div>
        <table>
            <tr><td class="k">PID</td><td>{{ $product->pid ?? '—' }}</td></tr>
            <tr><td class="k">UPC</td><td>{{ $product->upc }}</td></tr>
            <tr><td class="k">MRP</td><td>₹ {{ $mrp }} (incl. of all taxes)</td></tr>
            <tr><td class="k">UOM</td><td>{{ $product->uom ?? '—' }}</td></tr>
            <tr><td class="k">Mfg. date</td><td>{{ $made }}</td></tr>
            <tr><td class="k">Expiry date</td><td>{{ $expiry }}</td></tr>
        </table>
        <div class="a units">{{ $product->units_in_box ? $product->units_in_box.' UNITS IN BOX' : 'UNITS IN BOX: —' }}</div>
        @foreach ($bars as [$x, $w])
            <div class="bar" style="left: {{ 8 + $x }}mm; width: {{ $w }}mm"></div>
        @endforeach
        <div class="a upc">{{ $product->upc }}</div>
    </div>
@endfor
</body>
</html>
