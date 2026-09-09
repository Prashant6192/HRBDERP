<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 14mm 16mm; }
        body { font-family: Helvetica, Arial, sans-serif; color: #111; font-size: 9.5pt; }
        h1 { font-size: 16pt; margin: 0; }
        .muted { color: #555; }
        table.head { width: 100%; border-collapse: collapse; margin-top: 4mm; }
        table.head td { vertical-align: top; padding: 1mm 0; }
        table.head td.k { width: 30mm; color: #555; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 6mm; }
        table.lines th, table.lines td { border: 0.3mm solid #999; padding: 1.6mm 2mm; }
        table.lines th { background: #eee; text-align: left; font-size: 8.5pt; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        .short { font-weight: bold; }
        .level { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.3pt; }
        .sign { width: 100%; margin-top: 14mm; border-collapse: collapse; }
        .sign td { width: 33%; padding-top: 10mm; border-top: 0.3mm solid #999; text-align: center; color: #555; font-size: 8.5pt; }
        .foot { margin-top: 6mm; font-size: 7.5pt; color: #666; }
    </style>
</head>
<body>
    <table class="head"><tr>
        <td>
            <div class="muted" style="text-transform:uppercase;letter-spacing:.5pt">{{ $company }}</div>
            <h1>Production Material Request</h1>
            <div class="muted">{{ $request->store_kind->label() }} · {{ $request->warehouse->code }} {{ $request->warehouse->name }}</div>
        </td>
        <td style="text-align:right">
            <div style="font-size:15pt;font-weight:bold">{{ $request->number }}</div>
            <div class="muted">Raised {{ $request->requested_at->format('d M Y H:i') }}</div>
            <div class="muted">Status: {{ $request->status->label() }}</div>
        </td>
    </tr></table>

    <table class="head">
        <tr><td class="k">Plan</td><td>{{ $request->plan->number }}</td></tr>
        <tr><td class="k">Formula</td><td>{{ $request->plan->formula->code }} — {{ $request->plan->formula->name }}</td></tr>
        <tr><td class="k">Product</td><td>{{ $request->plan->product?->name ?? '—' }}</td></tr>
        <tr><td class="k">Batch</td><td>{{ rtrim(rtrim($request->plan->planned_quantity, '0'), '.') }} {{ $request->plan->plannedUom->code }}@if ($request->plan->planned_units) · {{ number_format($request->plan->planned_units) }} units @endif</td></tr>
        <tr><td class="k">Needed by</td><td>{{ $request->needed_by?->format('d M Y') ?? '—' }}</td></tr>
        <tr><td class="k">Requested by</td><td>{{ $request->requestedBy?->name ?? '—' }}</td></tr>
    </table>

    <table class="lines">
        <thead><tr>
            <th style="width:6mm">#</th>
            <th>Material</th>
            <th class="n">Required</th>
            <th class="n">In store</th>
            <th class="n">To order</th>
            <th class="n">Restock to</th>
            <th>Unit</th>
            <th>Level</th>
        </tr></thead>
        <tbody>
        @foreach ($request->lines as $line)
            @php $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, '.', ','), '0'), '.'); @endphp
            <tr>
                <td>{{ $line->line_no }}</td>
                <td><strong>{{ $line->item->name }}</strong><br><span class="muted">{{ $line->item->code }}</span></td>
                <td class="n">{{ $fmt($line->required_quantity) }}</td>
                <td class="n">{{ $fmt($line->available_quantity) }}</td>
                <td class="n {{ (float) $line->quantity_to_order > 0 ? 'short' : '' }}">{{ $fmt($line->quantity_to_order) }}</td>
                <td class="n">{{ $fmt($line->restock_quantity) }}</td>
                <td>{{ $line->uom->code }}</td>
                <td class="level">{{ $line->alert_level->shortLabel() }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="sign"><tr>
        <td>Requested by</td>
        <td>Store in-charge</td>
        <td>Purchase</td>
    </tr></table>

    <div class="foot">"To order" is the shortfall for this batch; "Restock to" also brings the store back to its reorder level. Printed {{ $printedAt->format('d M Y H:i') }}.</div>
</body>
</html>
