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
        .status { font-size: 8pt; color: #333; margin-top: 1mm; }
        .qrbox { text-align: center; width: 46mm; }
        .qrbox img { width: 40mm; height: 40mm; }
        .qrbox .code { font-family: "DejaVu Sans Mono", monospace; font-size: 15pt; font-weight: bold; letter-spacing: 2pt; margin-top: 1mm; }
        .qrbox .hint { font-size: 7pt; color: #333; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 5mm; }
        .parties td { width: 50%; vertical-align: top; border: 0.3mm solid #000; padding: 2.5mm 3mm; }
        .parties .k { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #444; }
        .parties .name { font-size: 11pt; font-weight: bold; margin-top: 0.5mm; }
        .parties .store { font-size: 9pt; margin-top: 0.5mm; }
        .parties .addr { font-size: 8.5pt; color: #222; margin-top: 1mm; line-height: 1.3; }
        table.facts { width: 100%; border-collapse: collapse; margin-top: 4mm; font-size: 8.5pt; }
        table.facts td { padding: 0.6mm 0; vertical-align: top; }
        table.facts td.k { color: #444; width: 26mm; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 5mm; font-size: 9pt; }
        table.lines th { text-align: left; border-bottom: 0.4mm solid #000; padding: 1.5mm 1.5mm; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        table.lines td { border-bottom: 0.2mm solid #999; padding: 1.8mm 1.5mm; vertical-align: top; }
        table.lines .r { text-align: right; white-space: nowrap; }
        table.lines .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8.5pt; }
        table.lines tfoot td { border-bottom: none; border-top: 0.4mm solid #000; font-weight: bold; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 10mm; }
        .sign td { width: 33%; vertical-align: bottom; padding: 0 3mm; }
        .sign .line { border-top: 0.3mm solid #000; padding-top: 1.5mm; font-size: 8pt; color: #333; margin-top: 14mm; }
        .foot { margin-top: 7mm; font-size: 8pt; color: #333; border: 0.3mm solid #000; padding: 2.5mm 3mm; line-height: 1.4; }
        .foot b { color: #000; }
    </style>
</head>
<body>
@php
    $address = function ($facility): string {
        $parts = array_filter([
            $facility->address_line_1, $facility->address_line_2,
            trim(implode(' ', array_filter([$facility->city, $facility->pincode]))),
            $facility->state,
        ]);
        return implode('<br>', array_map('e', $parts));
    };
    $qty = fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
@endphp
<table class="head">
    <tr>
        <td>
            <div class="company">{{ $company }}</div>
            <div class="title">STOCK TRANSFER CHALLAN</div>
            <div class="number">{{ $transfer->number }}</div>
            <div class="status">
                Dispatched {{ $transfer->dispatched_at?->format('d M Y H:i') ?? '—' }}
                @if($transfer->dispatcher) by {{ $transfer->dispatcher->name }} @endif
                @if($transfer->vehicle_ref) &middot; Vehicle {{ $transfer->vehicle_ref }} @endif
            </div>
        </td>
        <td class="qrbox">
            <img src="{{ $qr }}" alt="QR">
            <div class="code">{{ $code }}</div>
            <div class="hint">Inward code &middot; scan at the destination</div>
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="k">From</div>
            <div class="name">{{ $transfer->sourceFacility->name }}</div>
            <div class="store">{{ $transfer->sourceStore->code }} &middot; {{ $transfer->sourceStore->name }}</div>
            <div class="addr">{!! $address($transfer->sourceFacility) !!}@if($transfer->sourceFacility->gstin)<br>GSTIN {{ $transfer->sourceFacility->gstin }}@endif</div>
        </td>
        <td>
            <div class="k">To</div>
            <div class="name">{{ $transfer->destinationFacility->name }}</div>
            <div class="store">{{ $transfer->destinationStore->code }} &middot; {{ $transfer->destinationStore->name }}</div>
            <div class="addr">{!! $address($transfer->destinationFacility) !!}@if($transfer->destinationFacility->gstin)<br>GSTIN {{ $transfer->destinationFacility->gstin }}@endif</div>
        </td>
    </tr>
</table>

<table class="facts">
    <tr><td class="k">Reason</td><td>{{ $transfer->reason ?? '—' }}</td><td class="k">Expected</td><td>{{ $transfer->expected_at?->format('d M Y') ?? '—' }}</td></tr>
    <tr><td class="k">Requested by</td><td>{{ $transfer->requester?->name ?? '—' }}</td><td class="k">Approved by</td><td>{{ $transfer->approver?->name ?? '—' }}</td></tr>
    @if($transfer->requires_inspection)
    <tr><td class="k">On arrival</td><td colspan="3">Hold for inspection at the destination before release.</td></tr>
    @endif
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width:6mm">#</th>
            <th>Item</th>
            <th style="width:32mm">Batch</th>
            <th style="width:22mm">Expiry</th>
            <th class="r" style="width:28mm">Quantity</th>
        </tr>
    </thead>
    <tbody>
        @foreach($lines as $line)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td><b>{{ $line->item->name }}</b><br><span class="mono">{{ $line->item->code }}</span></td>
            <td class="mono">{{ $line->lot?->batch_number ?? '—' }}</td>
            <td>{{ $line->lot?->expiry_at?->format('d M Y') ?? '—' }}</td>
            <td class="r">{{ $qty($line->quantity_dispatched) }} {{ $line->item->stockUom?->code }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4">{{ count($lines) }} line{{ count($lines) === 1 ? '' : 's' }}</td>
            <td class="r">&nbsp;</td>
        </tr>
    </tfoot>
</table>

<table class="sign">
    <tr>
        <td><div class="line">Dispatched by</div></td>
        <td><div class="line">Driver / transporter</div></td>
        <td><div class="line">Received by (name, date)</div></td>
    </tr>
</table>

<div class="foot">
    <b>At {{ $transfer->destinationFacility->name }}:</b> open the ERP and scan this QR on the transfer&rsquo;s page (a phone camera or a handheld scanner), or type the inward code <b>{{ $code }}</b>. Then book in the quantities that arrived. Stock shows at {{ $transfer->destinationStore->name }} only after that.
    <br>{{ $url }}
</div>
</body>
</html>
