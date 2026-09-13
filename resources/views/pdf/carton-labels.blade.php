<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8mm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
        .label { width: 100%; height: 130mm; box-sizing: border-box; border: 0.8mm solid #000; padding: 6mm 8mm; page-break-after: always; }
        .label:last-child { page-break-after: auto; }
        .company { font-size: 9pt; letter-spacing: 1pt; text-transform: uppercase; color: #333; }
        .product { margin-top: 3mm; font-size: 22pt; font-weight: bold; line-height: 1.15; height: 22mm; overflow: hidden; }
        .net { font-size: 13pt; margin-top: 1mm; }
        table.facts { width: 100%; margin-top: 5mm; border-collapse: collapse; font-size: 12pt; }
        table.facts td { padding: 1.6mm 0; vertical-align: top; border-bottom: 0.2mm solid #999; }
        table.facts td.k { width: 34mm; color: #444; font-size: 9.5pt; text-transform: uppercase; letter-spacing: 0.5pt; }
        table.facts td.v { font-weight: bold; }
        .box { position: absolute; right: 8mm; top: 6mm; text-align: right; }
        .box .n { font-size: 34pt; font-weight: bold; line-height: 1; }
        .box .of { font-size: 9pt; color: #444; }
        .batch { font-family: "Courier New", Courier, monospace; letter-spacing: 1pt; }
        .foot { margin-top: 4mm; font-size: 8pt; color: #444; }
        .wrap { position: relative; }
    </style>
</head>
<body>
@foreach($boxes as $box)
<div class="label">
    <div class="wrap">
        <div class="company">{{ $company }}</div>
        <div class="box"><div class="of">BOX NO.</div><div class="n">{{ $box }}</div><div class="of">of {{ $lastBox }}</div></div>
        <div class="product">{{ $item->name }}</div>
        <div class="net">Net quantity: <strong>{{ $netContent }}</strong> &nbsp;&middot;&nbsp; {{ $plan['units_per_box'] }} units per box</div>

        <table class="facts">
            <tr><td class="k">Batch No.</td><td class="v batch">{{ $lot->batch_number }}</td><td class="k">Product code</td><td class="v">{{ $item->code }}</td></tr>
            <tr><td class="k">Mfg. date</td><td class="v">{{ $lot->manufactured_at?->format('d M Y') ?? '—' }}</td><td class="k">Expiry</td><td class="v">{{ $lot->expiry_at?->format('d M Y') ?? '—' }}</td></tr>
            <tr><td class="k">Gross weight</td><td class="v">{{ $plan['gross_weight_kg'] !== null ? rtrim(rtrim(number_format((float) $plan['gross_weight_kg'], 3, '.', ''), '0'), '.').' kg' : '—' }}</td><td class="k">MRP</td><td class="v">{{ $item->mrp !== null ? '₹'.number_format((float) $item->mrp, 2) : '—' }}</td></tr>
        </table>

        <div class="foot">
            QC released {{ $lot->qc_decided_at?->format('d M Y') ?? '—' }}. Store in a cool, dry place away from direct sunlight.
            @if(!empty($plan['remarks'])) &middot; {{ $plan['remarks'] }} @endif
        </div>
    </div>
</div>
@endforeach
</body>
</html>
