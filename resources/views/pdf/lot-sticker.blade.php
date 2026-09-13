<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* 80 x 50 mm by default (config erp.labels.batch_sticker). Helvetica is a PDF core font: no embedding, tiny file. */
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
        .sticker { width: {{ $width }}mm; height: {{ $height }}mm; box-sizing: border-box; padding: 2.5mm 3.5mm; border: 0.5mm solid #000; }
        .top { width: 100%; }
        .company { font-size: 6.5pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #333; }
        .approved { font-size: 9.5pt; font-weight: bold; text-align: right; border: 0.4mm solid #000; padding: 0.6mm 1.8mm; display: inline-block; }
        .item { margin-top: 1.5mm; font-size: 8pt; line-height: 1.2; height: 7mm; overflow: hidden; }
        .item .code { font-weight: bold; }
        .batch { margin-top: 0.8mm; font-size: 15pt; font-weight: bold; letter-spacing: 0.8pt; }
        table.facts { width: 100%; margin-top: 1.2mm; border-collapse: collapse; font-size: 7pt; }
        table.facts td { padding: 0.35mm 0; vertical-align: top; }
        table.facts td.k { width: 16mm; color: #444; }
        table.facts td.v { font-weight: bold; }
        .foot { margin-top: 1mm; font-size: 6pt; color: #444; }
    </style>
</head>
<body>
<div class="sticker">
    <table class="top"><tr>
        <td class="company">{{ $company }}</td>
        <td style="text-align:right"><span class="approved">QC APPROVED</span></td>
    </tr></table>

    <div class="item">
        <span class="code">{{ $item->code }}</span> &nbsp; {{ $item->name }}
    </div>

    <div class="batch">{{ $lot->batch_number }}</div>
    @isset($qr)
        <img src="{{ $qr }}" alt="{{ $scan_code }}" style="position:absolute; right:3mm; top:3mm; width:16mm; height:16mm;">
    @endisset

    <table class="facts">
        <tr>
            <td class="k">Received</td>
            <td class="v">{{ $lot->received_at?->format('d M Y') ?? '—' }}</td>
            <td class="k">Mfg. date</td>
            <td class="v">{{ $lot->manufactured_at?->format('d M Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Expiry</td>
            <td class="v">{{ $lot->expiry_at?->format('d M Y') ?? '—' }}</td>
            <td class="k">Quantity</td>
            <td class="v">{{ rtrim(rtrim(number_format((float) $lot->initial_quantity, 3, '.', ''), '0'), '.') }} {{ $item->stockUom?->code }}</td>
        </tr>
        <tr>
            <td class="k">Supplier ref</td>
            <td class="v">{{ $lot->supplier_batch_ref ?? '—' }}</td>
            <td class="k">QC ref</td>
            <td class="v">{{ $inspection?->number ?? '—' }}</td>
        </tr>
    </table>

    <div class="foot">
        Approved {{ $lot->qc_decided_at?->format('d M Y H:i') ?? '—' }}
        @if($lot->qcDecidedBy) by {{ $lot->qcDecidedBy->name }} @endif
        @if($lot->vendor) &middot; {{ $lot->vendor->name }} @endif
    </div>
</div>
</body>
</html>
