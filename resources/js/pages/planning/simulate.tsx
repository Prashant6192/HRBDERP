import { Head, router } from '@inertiajs/react';
import { FlaskRound, Sparkles } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { StatTile } from '@/components/intelligence/facility-filter';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { rupees } from '@/lib/intelligence';
import { ALERT_LABEL, ALERT_VARIANT, date, qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { simulate } from '@/routes/planning';
import type { StockAlertLevel } from '@/types';

type FormulaOption = {
    value: number;
    label: string;
    product: string | null;
    client: string | null;
    batch_uom_id: number | null;
    batch_uom: string | null;
    net_content: string | null;
    net_content_uom: string | null;
};

type Line = {
    store_kind: string;
    item_id: number;
    item_code: string;
    item_name: string;
    uom: string;
    required: string;
    available: string;
    shortage: string;
    level_now: StockAlertLevel;
    level_after: StockAlertLevel;
    standard_cost: string;
    material_cost: string;
    buy_price: string;
    purchase_value: string;
    vendor: string | null;
    lead_time_days: number | null;
};

type Result = {
    error?: string;
    formula: {
        id: number;
        code: string;
        name: string;
        product: string | null;
        client: string | null;
        version: number;
    };
    quantity: string;
    uom: string;
    kg: string;
    units: number | null;
    raw_materials: Line[];
    packaging: Line[];
    short: Line[];
    material_cost: string;
    cost_per_unit: string | null;
    purchase_value: string;
    longest_lead_days: number;
    earliest_start: string;
    fit: {
        days_needed: string;
        start: string;
        completion: string;
        capacity_kg: string | null;
        utilisation_next_week: number | null;
        pushed: { number: string; label: string; delay_days: number }[];
        note: string | null;
    } | null;
    warnings: string[];
    summary: string[];
};

type Input = {
    formula_id: number | null;
    quantity: string;
    uom_id: number | null;
    facility_id: number | null;
    start: string | null;
};

function LinesTable({ lines, title }: { lines: Line[]; title: string }) {
    if (lines.length === 0) return null;

    return (
        <section className="bg-card rounded-2xl border">
            <div className="border-b px-5 py-3">
                <h3 className="font-semibold">{title}</h3>
            </div>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Material</TableHead>
                            <TableHead className="text-right">
                                Required
                            </TableHead>
                            <TableHead className="text-right">
                                Available
                            </TableHead>
                            <TableHead className="text-right">Short</TableHead>
                            <TableHead>After</TableHead>
                            <TableHead className="text-right">Cost</TableHead>
                            <TableHead className="text-right">To buy</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((l) => {
                            const short = Number(l.shortage) > 0;
                            return (
                                <TableRow
                                    key={l.item_id}
                                    className={cn(short && 'bg-amber-500/5')}
                                >
                                    <TableCell>
                                        <div className="font-medium">
                                            {l.item_name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {l.item_code}
                                            {short && l.vendor
                                                ? ` · ${l.vendor}`
                                                : ''}
                                            {short && l.lead_time_days !== null
                                                ? ` · ${l.lead_time_days} d lead`
                                                : ''}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {qty(l.required)} {l.uom}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {qty(l.available)}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right font-medium tabular-nums',
                                            short &&
                                                'text-amber-700 dark:text-amber-300',
                                        )}
                                    >
                                        {short ? qty(l.shortage) : '—'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            variant={
                                                ALERT_VARIANT[l.level_after]
                                            }
                                        >
                                            {ALERT_LABEL[l.level_after]}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rupees(l.material_cost)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {short ? rupees(l.purchase_value) : '—'}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

export default function WhatIfSimulation({
    formulas,
    facilities,
    uoms,
    input,
    result,
}: {
    formulas: FormulaOption[];
    facilities: {
        id: number;
        code: string;
        name: string;
        daily_capacity_kg: string | null;
    }[];
    uoms: { id: number; code: string; name: string; dimension: string }[];
    input: Input;
    result: Result | null;
}) {
    const [formulaId, setFormulaId] = useState(
        input.formula_id ? String(input.formula_id) : '',
    );
    const [quantity, setQuantity] = useState(input.quantity ?? '');
    const [uomId, setUomId] = useState(
        input.uom_id ? String(input.uom_id) : '',
    );
    const [facilityId, setFacilityId] = useState(
        input.facility_id
            ? String(input.facility_id)
            : String(facilities[0]?.id ?? ''),
    );
    const [start, setStart] = useState(input.start ?? '');

    const chosen = formulas.find((f) => String(f.value) === formulaId);
    const effectiveUom =
        uomId || (chosen?.batch_uom_id ? String(chosen.batch_uom_id) : '');

    const run = (e: FormEvent) => {
        e.preventDefault();
        router.get(
            simulate().url,
            {
                formula_id: formulaId,
                quantity,
                uom_id: effectiveUom,
                facility_id: facilityId,
                ...(start ? { start } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title="What-if simulation" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="What-if simulation"
                    description="Ask “what if we manufacture 20,000 bottles of Product X?” and see the materials, the shortages, the purchase value, the expected cost, the machine time, the completion date and what it would push — without raising a plan."
                />

                <form
                    onSubmit={run}
                    className="bg-card grid gap-4 rounded-2xl border p-5 lg:grid-cols-[2fr_1fr_1fr_1.5fr_1fr_auto] lg:items-end"
                >
                    <div>
                        <Label htmlFor="formula">Product / formula</Label>
                        <Select value={formulaId} onValueChange={setFormulaId}>
                            <SelectTrigger id="formula" className="mt-1">
                                <SelectValue placeholder="Choose a formula" />
                            </SelectTrigger>
                            <SelectContent>
                                {formulas.map((f) => (
                                    <SelectItem
                                        key={f.value}
                                        value={String(f.value)}
                                    >
                                        {f.product ? `${f.product} — ` : ''}
                                        {f.label}
                                        {f.client ? ` (${f.client})` : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="quantity">Batch quantity</Label>
                        <Input
                            id="quantity"
                            inputMode="decimal"
                            className="mt-1"
                            value={quantity}
                            onChange={(e) => setQuantity(e.target.value)}
                            placeholder="e.g. 500"
                        />
                        {chosen?.net_content && (
                            <p className="text-muted-foreground mt-1 text-xs">
                                {chosen.net_content} {chosen.net_content_uom}{' '}
                                per unit
                            </p>
                        )}
                    </div>
                    <div>
                        <Label htmlFor="uom">Unit</Label>
                        <Select value={effectiveUom} onValueChange={setUomId}>
                            <SelectTrigger id="uom" className="mt-1">
                                <SelectValue placeholder="Unit" />
                            </SelectTrigger>
                            <SelectContent>
                                {uoms.map((u) => (
                                    <SelectItem key={u.id} value={String(u.id)}>
                                        {u.code} · {u.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="facility">Facility</Label>
                        <Select
                            value={facilityId}
                            onValueChange={setFacilityId}
                        >
                            <SelectTrigger id="facility" className="mt-1">
                                <SelectValue placeholder="Facility" />
                            </SelectTrigger>
                            <SelectContent>
                                {facilities.map((f) => (
                                    <SelectItem key={f.id} value={String(f.id)}>
                                        {f.name}
                                        {f.daily_capacity_kg
                                            ? ` · ${qty(f.daily_capacity_kg, 0)} KG/day`
                                            : ' · no capacity set'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div>
                        <Label htmlFor="start">Earliest start</Label>
                        <Input
                            id="start"
                            type="date"
                            className="mt-1"
                            value={start}
                            onChange={(e) => setStart(e.target.value)}
                        />
                    </div>
                    <Button type="submit" disabled={!formulaId || !quantity}>
                        <FlaskRound className="size-4" />
                        Simulate
                    </Button>
                </form>

                {result?.error && (
                    <p className="rounded-xl border border-red-500/30 bg-red-500/5 p-4 text-sm text-red-700 dark:text-red-300">
                        {result.error}
                    </p>
                )}

                {result && !result.error && (
                    <>
                        <section className="bg-card rounded-2xl border p-5">
                            <h2 className="inline-flex items-center gap-2 font-semibold">
                                <Sparkles className="text-primary size-4" />
                                {qty(result.quantity)} {result.uom} of{' '}
                                {result.formula.product ?? result.formula.name}
                                <span className="text-muted-foreground font-normal">
                                    · {result.formula.code} v
                                    {result.formula.version}
                                </span>
                            </h2>
                            <ul className="mt-3 space-y-1 text-sm">
                                {result.summary.map((line, i) => (
                                    <li
                                        key={i}
                                        className="flex items-start gap-2"
                                    >
                                        <span className="bg-primary mt-2 size-1.5 shrink-0 rounded-full" />
                                        <span>{line}</span>
                                    </li>
                                ))}
                                {result.warnings.map((w, i) => (
                                    <li
                                        key={`w${i}`}
                                        className="flex items-start gap-2 text-amber-700 dark:text-amber-300"
                                    >
                                        <span className="mt-2 size-1.5 shrink-0 rounded-full bg-amber-500" />
                                        <span>{w}</span>
                                    </li>
                                ))}
                            </ul>
                        </section>

                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                            <StatTile
                                label="Units"
                                value={
                                    result.units
                                        ? result.units.toLocaleString('en-IN')
                                        : '—'
                                }
                                hint={`${qty(result.kg, 0)} KG of bulk`}
                            />
                            <StatTile
                                label="Materials short"
                                value={result.short.length}
                                tone={
                                    result.short.length > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                            <StatTile
                                label="Purchase value"
                                value={rupees(result.purchase_value)}
                                hint="shortfall at best known price"
                            />
                            <StatTile
                                label="Expected cost"
                                value={rupees(result.material_cost)}
                                hint={
                                    result.cost_per_unit
                                        ? `${rupees(result.cost_per_unit, 2)} per unit, materials only`
                                        : 'materials at standard cost'
                                }
                            />
                            <StatTile
                                label="Machine time"
                                value={
                                    result.fit?.capacity_kg
                                        ? `${result.fit.days_needed} d`
                                        : '—'
                                }
                                hint={
                                    result.fit?.utilisation_next_week !==
                                        null &&
                                    result.fit?.utilisation_next_week !==
                                        undefined
                                        ? `next week already ${result.fit.utilisation_next_week}% booked`
                                        : undefined
                                }
                            />
                            <StatTile
                                label="Completion"
                                value={
                                    result.fit
                                        ? date(result.fit.completion)
                                        : '—'
                                }
                                hint={
                                    result.fit
                                        ? `start ${date(result.fit.start)}${result.longest_lead_days > 0 ? `, after ${result.longest_lead_days} d for materials` : ''}`
                                        : undefined
                                }
                                tone={
                                    result.fit && result.fit.pushed.length > 0
                                        ? 'warning'
                                        : 'default'
                                }
                            />
                        </div>

                        {result.fit && result.fit.pushed.length > 0 && (
                            <section className="rounded-2xl border border-amber-500/30 bg-amber-500/5 p-5 text-sm">
                                <h3 className="font-semibold text-amber-800 dark:text-amber-300">
                                    Impact on other production
                                </h3>
                                <ul className="mt-2 space-y-1">
                                    {result.fit.pushed.map((p) => (
                                        <li key={p.number}>
                                            <span className="font-medium">
                                                {p.number}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                {p.label}
                                            </span>{' '}
                                            would move by about {p.delay_days}{' '}
                                            working day
                                            {p.delay_days === 1 ? '' : 's'}.
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <LinesTable
                            lines={result.raw_materials}
                            title="Raw materials"
                        />
                        <LinesTable
                            lines={result.packaging}
                            title="Packaging"
                        />
                    </>
                )}
            </div>
        </>
    );
}

WhatIfSimulation.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'What-if simulation', href: simulate() },
    ],
};
