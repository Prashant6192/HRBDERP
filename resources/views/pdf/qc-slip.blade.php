<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; color: #000; }
        .slip { width: {{ $width }}mm; height: {{ $height }}mm; box-sizing: border-box; padding: 3mm 4mm; border: 0.5mm solid #000; }
        .company { font-size: 7pt; letter-spacing: 0.5pt; text-transform: uppercase; color: #333; }
        .verdict { margin-top: 1.5mm; font-size: 16pt; font-weight: bold; letter-spacing: 1pt; border: 0.6mm solid #000; padding: 1mm 2mm; display: inline-block; }
        .verdict.fail { background: #000; color: #fff; }
        .item { margin-top: 2mm; font-size: 8.5pt; line-height: 1.2; height: 8mm; overflow: hidden; }
        .item .code { font-weight: bold; }
        .batch { margin-top: 1mm; font-size: 14pt; font-weight: bold; letter-spacing: 0.5pt; }
        table.facts { width: 100%; margin-top: 1.5mm; border-collapse: collapse; font-size: 7.5pt; }
        table.facts td { padding: 0.4mm 0; vertical-align: top; }
        table.facts td.k { width: 18mm; color: #444; }
        table.facts td.v { font-weight: bold; }
        .foot { margin-top: 1mm; font-size: 6.5pt; color: #444; }
    </style>
</head>
<body>
<div class="slip">
    <div class="company">{{ $company }} &middot; Quality Control</div>
    <div class="verdict {{ $passed ? '' : 'fail' }}">{{ $passed ? 'QC PASSED' : 'QC REJECTED' }}</div>

    <div class="item"><span class="code">{{ $item->code }}</span> &nbsp; {{ $item->name }}</div>
    <div class="batch">{{ $lot?->batch_number ?? '—' }}</div>

    <table class="facts">
        <tr>
            <td class="k">QC ref</td><td class="v">{{ $inspection->number }}</td>
            <td class="k">Quantity</td><td class="v">{{ rtrim(rtrim(number_format((float) $inspection->quantity, 3, '.', ''), '0'), '.') }} {{ $item->stockUom?->code }}</td>
        </tr>
        <tr>
            <td class="k">Supplier ref</td><td class="v">{{ $lot?->supplier_batch_ref ?? '—' }}</td>
            <td class="k">{{ $passed ? 'Release to' : 'Held in' }}</td><td class="v">{{ $passed ? ($inspection->destinationWarehouse?->code ?? '—') : 'Quarantine' }}</td>
        </tr>
        @if(is_array($inspection->parameters) && $inspection->parameters !== [])
        <tr>
            <td class="k">Checked</td>
            <td class="v" colspan="3">
                @foreach(array_slice($inspection->parameters, 0, 4) as $p)
                    @php
                        $label = (string) ($p['name'] ?? '');
                        if (isset($p['value']) && $p['value'] !== null && $p['value'] !== '') {
                            $label .= ': '.$p['value'];
                        }
                        if (array_key_exists('passed', $p) && $p['passed'] !== null) {
                            $label .= $p['passed'] ? ' (ok)' : ' (fail)';
                        }
                    @endphp
                    {{ $label }}{{ $loop->last ? '' : ', ' }}
                @endforeach
            </td>
        </tr>
        @endif
    </table>

    <div class="foot">
        {{ $passed ? 'Passed' : 'Rejected' }} {{ $inspection->decided_at?->format('d M Y H:i') ?? '—' }}
        @if($inspection->decidedBy) by {{ $inspection->decidedBy->name }} @endif
        @if($lot?->vendor) &middot; {{ $lot->vendor->name }} @endif
    </div>
</div>
</body>
</html>
