<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 14mm 16mm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; font-size: 9.5pt; }
        .head { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .company { font-size: 8pt; letter-spacing: 0.6pt; text-transform: uppercase; color: #333; }
        .title { font-size: 17pt; font-weight: bold; letter-spacing: 1pt; margin-top: 1mm; }
        .number { font-size: 14pt; font-weight: bold; margin-top: 2mm; font-family: "DejaVu Sans Mono", monospace; }
        .courier { text-align: right; }
        .courier .k { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #444; }
        .courier .v { font-size: 16pt; font-weight: bold; }
        .courier .count { font-size: 30pt; font-weight: bold; line-height: 1; margin-top: 2mm; }
        table.facts { width: 100%; border-collapse: collapse; margin-top: 4mm; font-size: 8.5pt; }
        table.facts td { padding: 0.6mm 0; vertical-align: top; }
        table.facts td.k { color: #444; width: 30mm; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 5mm; font-size: 8.5pt; }
        table.lines th { text-align: left; border-bottom: 0.4mm solid #000; padding: 1.5mm 1.2mm; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        table.lines td { border-bottom: 0.2mm solid #999; padding: 1.6mm 1.2mm; vertical-align: top; }
        table.lines .r { text-align: right; white-space: nowrap; }
        table.lines .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; }
        .tick { display: inline-block; width: 3.5mm; height: 3.5mm; border: 0.3mm solid #000; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 12mm; }
        .sign td { width: 50%; vertical-align: bottom; padding: 0 3mm; }
        .sign .line { border-top: 0.3mm solid #000; padding-top: 1.5mm; font-size: 8pt; color: #333; margin-top: 16mm; }
        .foot { margin-top: 6mm; font-size: 7.5pt; color: #333; line-height: 1.4; }
    </style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <div class="company">{{ $company }}</div>
            <div class="title">COURIER HANDOVER</div>
            <div class="number">{{ $sheet->number }}</div>
        </td>
        <td class="courier">
            <div class="k">Courier</div>
            <div class="v">{{ $sheet->courier }}</div>
            <div class="count">{{ $sheet->shipment_count }}</div>
            <div class="k">parcels</div>
        </td>
    </tr>
</table>

<table class="facts">
    <tr><td class="k">From</td><td>{{ $sheet->facility?->name }} · {{ $sheet->warehouse?->name }}</td></tr>
    <tr><td class="k">Handed over</td><td>{{ $at }}</td></tr>
    <tr><td class="k">By</td><td>{{ $sheet->handedOverBy?->name ?? '—' }}</td></tr>
    @if ($sheet->received_by_name)
        <tr><td class="k">Collected by</td><td>{{ $sheet->received_by_name }}</td></tr>
    @endif
</table>

<table class="lines">
    <thead>
    <tr>
        <th style="width: 7mm">#</th>
        <th>AWB</th>
        <th>Order</th>
        <th>Marketplace</th>
        <th>Payment</th>
        <th class="r">Collect</th>
        <th style="width: 10mm">Seen</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($sheet->shipments as $i => $s)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td class="mono">{{ $s->awb ?? '—' }}</td>
            <td class="mono">{{ $s->order_number ?? '—' }}</td>
            <td>{{ $s->marketplace?->name }}</td>
            <td>{{ $s->payment_mode->label() }}</td>
            <td class="r">{{ $s->payment_mode->value === 'cod' && $s->payable_amount ? 'Rs. '.number_format((float) $s->payable_amount, 2) : '' }}</td>
            <td><span class="tick"></span></td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="sign">
    <tr>
        <td><div class="line">Handed over by (depot)</div></td>
        <td><div class="line">Received {{ $sheet->shipment_count }} parcels — name, signature and time (courier)</div></td>
    </tr>
</table>

<div class="foot">
    Count the parcels against this sheet before signing. A parcel on this sheet is recorded in the ERP as with {{ $sheet->courier }} from {{ $at }}.
</div>
</body>
</html>
