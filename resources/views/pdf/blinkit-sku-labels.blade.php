<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* One 100 × 25 mm row of the roll per page: two 50 × 25 mm labels side by side.
           Everything sits at a fixed place on the label, so nothing can push onto another page.
           DejaVu Sans carries the rupee sign. */
        @page { margin: 0; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; color: #000; }
        .row { position: relative; width: 100mm; height: 25mm; overflow: hidden; page-break-after: always; }
        .row.last { page-break-after: auto; }
        .label { position: absolute; top: 0; width: 50mm; height: 25mm; overflow: hidden; }
        .t { position: absolute; left: 2.5mm; right: 2.5mm; white-space: nowrap; overflow: hidden; }
        .brand { top: 1.2mm; font-size: 6.4pt; font-weight: bold; }
        .mrp { top: 1.2mm; font-size: 6.4pt; font-weight: bold; text-align: right; }
        .name { top: 4.3mm; font-size: 4.6pt; line-height: 1.15; white-space: normal; height: 5.2mm; }
        .bar { position: absolute; top: 9.9mm; height: 7.9mm; background: #000; }
        .upc { top: 18mm; font-size: 5.4pt; text-align: center; letter-spacing: 0.4pt; }
        .meta { top: 21mm; font-size: 4.8pt; }
        .uom { top: 21mm; font-size: 4.8pt; text-align: right; }
    </style>
</head>
<body>
@for ($r = 0; $r < $rows; $r++)
    <div class="row{{ $r === $rows - 1 ? ' last' : '' }}">
        @for ($c = 0; $c < 2; $c++)
            @if ($r * 2 + $c < $copies)
                <div class="label" style="left: {{ $c * 50 }}mm">
                    <div class="t brand">{{ $product->brand_name }}</div>
                    <div class="t mrp">MRP ₹{{ $mrp }}</div>
                    <div class="t name">{{ \Illuminate\Support\Str::limit($product->product_name, 90) }}</div>
                    @foreach ($bars as [$x, $w])
                        <div class="bar" style="left: {{ 2.5 + $x }}mm; width: {{ $w }}mm"></div>
                    @endforeach
                    <div class="t upc">{{ $product->upc }}</div>
                    <div class="t meta">MFG {{ $made }} &nbsp; EXP {{ $expiry }}</div>
                    <div class="t uom">{{ $product->uom }}</div>
                </div>
            @endif
        @endfor
    </div>
@endfor
</body>
</html>
