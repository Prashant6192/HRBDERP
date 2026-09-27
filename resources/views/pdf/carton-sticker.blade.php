<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #000; font-size: 7.4pt; line-height: 1.25; }
        .s { width: 92mm; height: 142mm; padding: 3.5mm 4mm; page-break-after: always; position: relative; overflow: hidden; }
        .s:last-child { page-break-after: auto; }
        .brand { font-size: 10pt; font-weight: bold; letter-spacing: 0.6pt; text-transform: uppercase; }
        .product { font-size: 13pt; font-weight: bold; line-height: 1.15; margin-top: 1mm; max-height: 12mm; overflow: hidden; }
        .qty { font-size: 9pt; font-weight: bold; margin-top: 1mm; }
        hr { border: 0; border-top: 0.4mm solid #000; margin: 2mm 0; width: 92mm; }
        table.f { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.f td { padding: 0.6mm 0; }
        table.f td.k { width: 26mm; color: #222; }
        table.f td.v { font-weight: bold; }
        .mono { font-family: DejaVu Sans Mono, Courier, monospace; }
        table.mrp { width: 92mm; border-collapse: collapse; margin-top: 2mm; }
        table.mrp td.b { border: 0.4mm solid #000; padding: 1.2mm 2mm; width: 40mm; vertical-align: top; }
        table.mrp td.gap { width: 2mm; }
        table.mrp .l { font-size: 6.5pt; }
        table.mrp .v { font-size: 12pt; font-weight: bold; }
        .fine { font-size: 6.6pt; margin-top: 1.6mm; }
        .foot { position: absolute; left: 4mm; width: 92mm; bottom: 3mm; }
        .foot table { width: 100%; border-collapse: collapse; }
        .foot img { width: 30mm; height: 30mm; }
        .ctn { font-size: 7pt; letter-spacing: 1pt; }
        .n { font-size: 30pt; font-weight: bold; line-height: 1; }
        .of { font-size: 10pt; }
    </style>
</head>
<body>
@foreach($boxes as $box)
<div class="s">
    <div class="brand">{{ $s['brand'] }}</div>
    <div class="product">{{ $s['product'] }}</div>
    <div class="qty">{{ $s['units'] }} pcs @if($s['net_per_unit']) × {{ $s['net_per_unit'] }} @endif @if($s['net_carton']) · Net {{ $s['net_carton'] }} @endif</div>
    <hr>
    <table class="f">
        <tr><td class="k">Batch No.</td><td class="v mono">{{ $s['batch'] }}</td></tr>
        <tr><td class="k">Mfg.</td><td class="v">{{ $s['mfg'] ?? '—' }}</td></tr>
        <tr><td class="k">Use before</td><td class="v">{{ $s['expiry'] ?? '—' }}</td></tr>
        <tr><td class="k">Gross wt.</td><td class="v">{{ $s['gross_weight'] ?? '—' }}</td></tr>
        <tr><td class="k">Lic. No.</td><td class="v">{{ $s['licence'] ?? '—' }}</td></tr>
    </table>
    <table class="mrp"><tr>
        <td class="b"><div class="l">MRP per piece (incl. of all taxes)</div><div class="v">{{ $s['mrp_unit'] ? '₹ '.$s['mrp_unit'] : '—' }}</div></td>
        <td class="gap"></td>
        <td class="b"><div class="l">MRP of carton, {{ $s['units'] }} pcs (incl. of all taxes)</div><div class="v">{{ $s['mrp_carton'] ? '₹ '.$s['mrp_carton'] : '—' }}</div></td>
    </tr></table>
    <div class="fine">Mfd. by: {{ $s['manufacturer'] }}</div>
    @if($s['marketed_by'])<div class="fine">{{ $s['marketed_by'] }}</div>@endif
    @if($s['consumer_care'])<div class="fine">Consumer care: {{ $s['consumer_care'] }}</div>@endif
    @if($s['remarks'])<div class="fine"><strong>{{ $s['remarks'] }}</strong></div>@endif
    <div class="foot"><table><tr>
        <td style="width: 32mm"><img src="{{ $box['qr'] }}" alt=""></td>
        <td style="vertical-align: bottom; padding-left: 3mm">
            <div class="ctn">CARTON</div>
            <div class="n">{{ sprintf('%02d', $box['no']) }}</div>
            <div class="of">of {{ $s['last_box'] }}</div>
            <div class="fine mono">{{ $box['code'] }}</div>
            @if($s['barcode'])<div class="fine mono">EAN {{ $s['barcode'] }}</div>@endif
        </td>
    </tr></table></div>
</div>
@endforeach
</body>
</html>
