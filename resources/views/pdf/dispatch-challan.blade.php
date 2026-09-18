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
        .invoice { text-align: right; }
        .invoice .k { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #444; }
        .invoice .v { font-size: 11pt; font-weight: bold; }
        .invoice .irn { font-family: "DejaVu Sans Mono", monospace; font-size: 6.5pt; word-break: break-all; margin-top: 1mm; }
        .parties { width: 100%; border-collapse: collapse; margin-top: 5mm; }
        .parties td { width: 33%; vertical-align: top; border: 0.3mm solid #000; padding: 2.5mm 3mm; }
        .parties .k { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5pt; color: #444; }
        .parties .name { font-size: 10.5pt; font-weight: bold; margin-top: 0.5mm; }
        .parties .addr { font-size: 8.5pt; color: #222; margin-top: 1mm; line-height: 1.3; }
        table.facts { width: 100%; border-collapse: collapse; margin-top: 4mm; font-size: 8.5pt; }
        table.facts td { padding: 0.6mm 0; vertical-align: top; }
        table.facts td.k { color: #444; width: 30mm; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 5mm; font-size: 8.5pt; }
        table.lines th { text-align: left; border-bottom: 0.4mm solid #000; padding: 1.5mm 1.2mm; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.4pt; }
        table.lines td { border-bottom: 0.2mm solid #999; padding: 1.8mm 1.2mm; vertical-align: top; }
        table.lines .r { text-align: right; white-space: nowrap; }
        table.lines .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 8pt; }
        table.lines tfoot td { border-bottom: none; padding: 1mm 1.2mm; }
        table.lines tfoot tr.total td { border-top: 0.4mm solid #000; font-weight: bold; font-size: 10pt; padding-top: 2mm; }
        .sign { width: 100%; border-collapse: collapse; margin-top: 10mm; }
        .sign td { width: 33%; vertical-align: bottom; padding: 0 3mm; }
        .sign .line { border-top: 0.3mm solid #000; padding-top: 1.5mm; font-size: 8pt; color: #333; margin-top: 14mm; }
        .foot { margin-top: 6mm; font-size: 7.5pt; color: #333; line-height: 1.4; }
    </style>
</head>
<body>
@php
    $money = fn ($v): string => number_format((float) $v, 2, '.', ',');
    $qty = fn ($v): string => rtrim(rtrim(number_format((float) $v, 3, '.', ''), '0'), '.');
    $customer = $dispatch->customer;
    $billTo = array_filter([
        $customer->billing_address_line_1, $customer->billing_address_line_2,
        trim(implode(' ', array_filter([$customer->billing_city, $customer->billing_pincode]))),
        $customer->billing_state,
    ]);
    $shipTo = $dispatch->ship_to_name === null ? null : array_filter([
        $dispatch->ship_to_address_line_1, $dispatch->ship_to_address_line_2,
        trim(implode(' ', array_filter([$dispatch->ship_to_city, $dispatch->ship_to_pincode]))),
        $dispatch->ship_to_state,
    ]);
    $sellerAddr = array_filter([
        $seller['address_1'], $seller['address_2'],
        trim(implode(' ', array_filter([$seller['city'], $seller['pincode']]))),
    ]);
    $tax = (float) $dispatch->cgst + (float) $dispatch->sgst + (float) $dispatch->igst;
@endphp
<table class="head">
    <tr>
        <td>
            <div class="company">{{ $company }}</div>
            <div class="title">DELIVERY CHALLAN &middot; PACKING LIST</div>
            <div class="number">{{ $dispatch->number }}</div>
            <div class="status">
                {{ $dispatch->status->label() }}
                @if($dispatch->dispatched_at) &middot; left {{ $dispatch->dispatched_at->format('d M Y H:i') }} @endif
                @if($dispatch->dispatcher) by {{ $dispatch->dispatcher->name }} @endif
            </div>
        </td>
        <td class="invoice">
            <div class="k">Against tax invoice</div>
            <div class="v">{{ $dispatch->invoice_number ?? '—' }}</div>
            <div>{{ $dispatch->invoice_date?->format('d M Y') ?? '' }}</div>
            @if($dispatch->irn)
                <div class="k" style="margin-top:2mm">IRN &middot; Ack {{ $dispatch->ack_number }} of {{ $dispatch->ack_date?->format('d M Y') }}</div>
                <div class="irn">{{ $dispatch->irn }}</div>
            @endif
        </td>
    </tr>
</table>

<table class="parties">
    <tr>
        <td>
            <div class="k">Consignor &middot; seller</div>
            <div class="name">{{ $seller['legal_name'] }}</div>
            <div class="addr">{{ $dispatch->facility->name }} &middot; {{ $dispatch->warehouse->code }} {{ $dispatch->warehouse->name }}<br>{!! implode('<br>', array_map('e', $sellerAddr)) !!}@if($seller['gstin'])<br>GSTIN {{ $seller['gstin'] }}@endif</div>
        </td>
        <td>
            <div class="k">Bill to</div>
            <div class="name">{{ $customer->legal_name ?: $customer->name }}</div>
            <div class="addr">{!! implode('<br>', array_map('e', $billTo)) !!}@if($customer->gstin)<br>GSTIN {{ $customer->gstin }}@else<br>Unregistered @endif</div>
        </td>
        <td>
            <div class="k">Ship to</div>
            @if($shipTo !== null)
                <div class="name">{{ $dispatch->ship_to_name }}</div>
                <div class="addr">{!! implode('<br>', array_map('e', $shipTo)) !!}@if($dispatch->ship_to_gstin)<br>GSTIN {{ $dispatch->ship_to_gstin }}@endif</div>
            @else
                <div class="name">{{ $customer->name }}</div>
                <div class="addr">Same as billing address</div>
            @endif
        </td>
    </tr>
</table>

<table class="facts">
    <tr>
        <td class="k">Place of supply</td><td>{{ $dispatch->place_of_supply }} {{ $stateName ? '— '.$stateName : '' }} ({{ $dispatch->is_interstate ? 'inter-state, IGST' : 'intra-state, CGST + SGST' }})</td>
        <td class="k">Customer ref.</td><td>{{ $dispatch->reference ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Transporter</td><td>{{ $dispatch->transporter_name ?? '—' }}@if($dispatch->transporter_gstin) &middot; {{ $dispatch->transporter_gstin }}@endif</td>
        <td class="k">Vehicle</td><td>{{ $dispatch->vehicle_number ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">LR / docket</td><td>{{ $dispatch->lr_number ?? '—' }}@if($dispatch->lr_date) of {{ $dispatch->lr_date->format('d M Y') }}@endif</td>
        <td class="k">E-way bill</td><td>{{ $dispatch->eway_bill_number ?? '—' }}@if($dispatch->eway_bill_date) of {{ $dispatch->eway_bill_date->format('d M Y') }}@endif @if($dispatch->distance_km) &middot; {{ $dispatch->distance_km }} km @endif</td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th style="width:5mm">#</th>
            <th>Product</th>
            <th style="width:16mm">HSN</th>
            <th style="width:30mm">Batch</th>
            <th style="width:18mm">Expiry</th>
            <th class="r" style="width:20mm">Quantity</th>
            <th class="r" style="width:18mm">Rate</th>
            <th class="r" style="width:20mm">Taxable</th>
            <th class="r" style="width:12mm">GST</th>
            <th class="r" style="width:22mm">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach($dispatch->lines as $line)
        <tr>
            <td>{{ $line->line_no }}</td>
            <td><b>{{ $line->description ?: $line->item->name }}</b><br><span class="mono">{{ $line->item->code }}</span></td>
            <td class="mono">{{ $line->hsn_code ?? '—' }}</td>
            <td class="mono">{{ $line->lot?->batch_number ?? '—' }}</td>
            <td>{{ $line->lot?->expiry_at?->format('d M Y') ?? '—' }}</td>
            <td class="r">{{ $qty($line->quantity) }} {{ $line->item->stockUom?->code }}</td>
            <td class="r">{{ $money($line->unit_price) }}</td>
            <td class="r">{{ $money($line->taxable_value) }}</td>
            <td class="r">{{ $qty($line->gst_rate) }}%</td>
            <td class="r">{{ $money($line->line_total) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="7" class="r">Taxable value</td><td colspan="3" class="r">{{ $money($dispatch->taxable_value) }}</td></tr>
        @if($dispatch->is_interstate)
            <tr><td colspan="7" class="r">IGST</td><td colspan="3" class="r">{{ $money($dispatch->igst) }}</td></tr>
        @else
            <tr><td colspan="7" class="r">CGST</td><td colspan="3" class="r">{{ $money($dispatch->cgst) }}</td></tr>
            <tr><td colspan="7" class="r">SGST</td><td colspan="3" class="r">{{ $money($dispatch->sgst) }}</td></tr>
        @endif
        @if((float) $dispatch->other_charges != 0)
            <tr><td colspan="7" class="r">Other charges</td><td colspan="3" class="r">{{ $money($dispatch->other_charges) }}</td></tr>
        @endif
        @if((float) $dispatch->round_off != 0)
            <tr><td colspan="7" class="r">Round off</td><td colspan="3" class="r">{{ $money($dispatch->round_off) }}</td></tr>
        @endif
        <tr class="total"><td colspan="7" class="r">Total (₹)</td><td colspan="3" class="r">{{ $money($dispatch->total_value) }}</td></tr>
    </tfoot>
</table>

<table class="sign">
    <tr>
        <td><div class="line">Packed and checked by</div></td>
        <td><div class="line">Driver / transporter</div></td>
        <td><div class="line">Received by (name, date, stamp)</div></td>
    </tr>
</table>

<div class="foot">
    {{ count($dispatch->lines) }} line{{ count($dispatch->lines) === 1 ? '' : 's' }}, tax ₹{{ $money($tax) }}. Goods once sold will not be taken back except as agreed in writing. This challan accompanies tax invoice {{ $dispatch->invoice_number ?? '—' }}; the invoice is the document of sale.
</div>
</body>
</html>
