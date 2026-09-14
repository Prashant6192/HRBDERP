<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        /* 80 x 50 mm by default (config erp.labels.batch_sticker). Helvetica is a PDF core font: no embedding, tiny file. */
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
        /* dompdf ignores box-sizing, so the padding and border are taken off the page size by hand. */
        .sticker { width: {{ $width - 7 }}mm; height: {{ $height - 6 }}mm; padding: 2mm 3mm; border: 0.5mm solid #000; overflow: hidden; }
        /* Two columns: the words on the left, the QR and the stamp floated in their own column on the right, so nothing overlaps. */
        .right { float: right; width: 16mm; text-align: right; }
        .left { margin-right: 18mm; height: 20mm; }
        .clear { clear: both; height: 0; }
        .qr { width: 15mm; height: 15mm; display: block; margin-left: auto; }
        .company { font-size: 6.5pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #333; }
        .approved { margin-top: 0.6mm; font-size: 5.5pt; font-weight: bold; text-align: center; border: 0.4mm solid #000; padding: 0.4mm 0; white-space: nowrap; letter-spacing: 0.2pt; }
        .item { margin-top: 1.5mm; font-size: 8pt; line-height: 1.2; max-height: 7.5mm; overflow: hidden; }
        .item .code { font-weight: bold; }
        .batch { margin-top: 0.6mm; font-size: 13.5pt; font-weight: bold; letter-spacing: 0.5pt; }
        table.facts { width: 100%; margin-top: 1.2mm; border-collapse: collapse; font-size: 7pt; }
        table.facts td { padding: 0.35mm 0; vertical-align: top; }
        table.facts td.k { width: 15mm; color: #444; }
        table.facts td.v { font-weight: bold; }
        .foot { margin-top: 1mm; font-size: 6pt; color: #444; }
    </style>
</head>
<body>
<div class="sticker">
    <div class="right">
        @isset($qr)
            <img src="{{ $qr }}" alt="{{ $scan_code }}" class="qr">
        @endisset
        <div class="approved">QC APPROVED</div>
    </div>
    <div class="left">
        <div class="company">{{ $company }}</div>
        <div class="item">
            <span class="code">{{ $item->code }}</span> &nbsp; {{ $item->name }}
        </div>
        <div class="batch">{{ $lot->batch_number }}</div>
    </div>
    <div class="clear"></div>

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
