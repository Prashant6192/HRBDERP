<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* 100 x 70 mm. Helvetica is a PDF core font: no embedding, tiny file. */
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
        .sticker { width: 100mm; height: 70mm; box-sizing: border-box; padding: 4mm 5mm; border: 0.6mm solid #000; }
        .top { width: 100%; }
        .company { font-size: 8pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #333; }
        .approved { font-size: 13pt; font-weight: bold; text-align: right; border: 0.5mm solid #000; padding: 1mm 2.5mm; display: inline-block; }
        .item { margin-top: 2.5mm; font-size: 9.5pt; line-height: 1.2; height: 9mm; overflow: hidden; }
        .item .code { font-weight: bold; }
        .batch { margin-top: 1.5mm; font-size: 20pt; font-weight: bold; letter-spacing: 1pt; }
        table.facts { width: 100%; margin-top: 2mm; border-collapse: collapse; font-size: 8.5pt; }
        table.facts td { padding: 0.6mm 0; vertical-align: top; }
        table.facts td.k { width: 22mm; color: #444; }
        table.facts td.v { font-weight: bold; }
        .foot { margin-top: 1.5mm; font-size: 7pt; color: #444; }
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
