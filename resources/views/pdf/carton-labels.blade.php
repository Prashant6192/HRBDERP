<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8mm; }
        body { margin: 0; font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #000; }
        .label { width: 178mm; height: 118mm; border: 0.8mm solid #000; padding: 5mm 7mm; page-break-after: always; position: relative; }
        .label:last-child { page-break-after: auto; }
        .company { font-size: 9pt; letter-spacing: 1pt; text-transform: uppercase; color: #333; }
        .product { margin-top: 2mm; font-size: 20pt; font-weight: bold; line-height: 1.15; height: 18mm; overflow: hidden; width: 140mm; }
        .net { font-size: 11pt; margin-top: 1mm; }
        table.facts { width: 150mm; margin-top: 3mm; border-collapse: collapse; font-size: 10.5pt; }
        table.facts td { padding: 1.2mm 0; vertical-align: top; border-bottom: 0.2mm solid #999; }
        table.facts td.k { width: 30mm; color: #444; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.5pt; }
        table.facts td.v { font-weight: bold; }
        .box { position: absolute; right: 7mm; top: 5mm; text-align: right; }
        .box .n { font-size: 32pt; font-weight: bold; line-height: 1; }
        .box .of { font-size: 9pt; color: #444; }
        .qr { position: absolute; right: 7mm; bottom: 6mm; text-align: center; font-size: 6.5pt; }
        .qr img { width: 30mm; height: 30mm; }
        .batch { font-family: DejaVu Sans Mono, Courier, monospace; letter-spacing: 0.5pt; }
        .foot { margin-top: 3mm; font-size: 7.5pt; color: #222; width: 150mm; line-height: 1.3; }
    </style>
</head>
<body>
@foreach($boxes as $box)
<div class="label">
    <div class="company">{{ $s['brand'] }}</div>
    <div class="box"><div class="of">BOX NO.</div><div class="n">{{ $box['no'] }}</div><div class="of">of {{ $s['last_box'] }}</div></div>
    <div class="product">{{ $s['product'] }}</div>
    <div class="net">{{ $s['units'] }} pcs @if($s['net_per_unit']) × {{ $s['net_per_unit'] }} @endif @if($s['net_carton']) &nbsp;·&nbsp; Net quantity <strong>{{ $s['net_carton'] }}</strong> @endif</div>

    <table class="facts">
        <tr><td class="k">Batch No.</td><td class="v batch">{{ $s['batch'] }}</td><td class="k">Product code</td><td class="v">{{ $s['code'] }}</td></tr>
        <tr><td class="k">Mfg.</td><td class="v">{{ $s['mfg'] ?? '—' }}</td><td class="k">Use before</td><td class="v">{{ $s['expiry'] ?? '—' }}</td></tr>
        <tr><td class="k">MRP / piece</td><td class="v">{{ $s['mrp_unit'] ? '₹ '.$s['mrp_unit'] : '—' }}</td><td class="k">MRP / carton</td><td class="v">{{ $s['mrp_carton'] ? '₹ '.$s['mrp_carton'] : '—' }}</td></tr>
        <tr><td class="k">Gross weight</td><td class="v">{{ $s['gross_weight'] ?? '—' }}</td><td class="k">Lic. No.</td><td class="v">{{ $s['licence'] ?? '—' }}</td></tr>
    </table>

    <div class="foot">
        MRP inclusive of all taxes. Mfd. by: {{ $s['manufacturer'] }}.
        @if($s['marketed_by']) {{ $s['marketed_by'] }}. @endif
        @if($s['consumer_care']) Consumer care: {{ $s['consumer_care'] }}. @endif
        Store in a cool, dry place away from direct sunlight.
        @if($s['remarks']) <strong>{{ $s['remarks'] }}</strong> @endif
    </div>
    <div class="qr"><img src="{{ $box['qr'] }}" alt=""><br>{{ $box['code'] }}</div>
</div>
@endforeach
</body>
</html>
