import { useForm } from '@inertiajs/react';
import { Calculator, Scale } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
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
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { adjust } from '@/routes/manufacturing';

export type ConsumptionLine = {
    item_id: number;
    code: string;
    name: string;
    store_kind: string;
    unit: string | null;
    standard: string;
    issued: string;
    consumed: string;
    returned: string;
    wastage: string;
    actual: string;
    variance: string;
    variance_percent: string | null;
    flag: boolean;
};

export type CostVariance = {
    standard_total: string;
    actual_total: string;
    variance: string;
    variance_percent: string | null;
    standard_per_unit: string | null;
    actual_per_unit: string | null;
    planned_units: number | null;
    output_units: number | null;
    reasons: { key: string; label: string; amount: string; text: string }[];
    sentence: string;
    has_actuals: boolean;
};

export type BatchAnalytics = {
    consumption: { lines: ConsumptionLine[]; totals: Record<string, string> };
    cost: CostVariance | null;
};

function signed(value: string, unit?: string | null): string {
    const n = Number(value);
    if (n === 0) return '—';
    return `${n > 0 ? '+' : ''}${qty(value)}${unit ? ` ${unit}` : ''}`;
}

/**
 * Standard vs issued vs consumed vs returned vs wastage per material, the
 * cost variance with its reasons, and the form for booking a return or a
 * wastage against the batch.
 */
export function BatchAnalyticsPanel({
    orderId,
    analytics,
    lots,
    canAdjust,
}: {
    orderId: number;
    analytics: BatchAnalytics;
    lots: { item_id: number; lot_id: number; batch_number: string }[];
    canAdjust: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        kind: 'return',
        item_id: String(analytics.consumption.lines[0]?.item_id ?? ''),
        lot_id: '',
        quantity: '',
        reason: '',
    });

    const itemLots = lots.filter(
        (l) => String(l.item_id) === form.data.item_id,
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(adjust(orderId).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('quantity', 'reason', 'lot_id');
                setOpen(false);
            },
        });
    };

    const { lines, totals } = analytics.consumption;
    const cost = analytics.cost;

    return (
        <>
            <section className="bg-card rounded-xl border">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                    <div>
                        <h2 className="inline-flex items-center gap-2 font-semibold">
                            <Scale className="text-primary size-4" />
                            Material consumption
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Standard is the recipe; issued is what the store
                            handed over; consumed is what the ledger took;
                            actual is consumed less returned.
                        </p>
                    </div>
                    {canAdjust && (
                        <Button
                            size="sm"
                            variant={open ? 'secondary' : 'outline'}
                            onClick={() => setOpen((o) => !o)}
                        >
                            Record return or wastage
                        </Button>
                    )}
                </div>

                {open && canAdjust && (
                    <form
                        onSubmit={submit}
                        className="bg-muted/30 grid gap-3 border-b px-5 py-4 sm:grid-cols-[8rem_1fr_1fr_8rem_1fr_auto] sm:items-end"
                    >
                        <div>
                            <Label>Kind</Label>
                            <Select
                                value={form.data.kind}
                                onValueChange={(v) => form.setData('kind', v)}
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="return">
                                        Return to store
                                    </SelectItem>
                                    <SelectItem value="wastage">
                                        Wastage
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Material</Label>
                            <Select
                                value={form.data.item_id}
                                onValueChange={(v) => {
                                    form.setData('item_id', v);
                                    form.setData('lot_id', '');
                                }}
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {lines.map((l) => (
                                        <SelectItem
                                            key={l.item_id}
                                            value={String(l.item_id)}
                                        >
                                            {l.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.item_id} />
                        </div>
                        <div>
                            <Label>Batch</Label>
                            <Select
                                value={form.data.lot_id || 'none'}
                                onValueChange={(v) =>
                                    form.setData(
                                        'lot_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue placeholder="Any" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Not specified
                                    </SelectItem>
                                    {itemLots.map((l) => (
                                        <SelectItem
                                            key={l.lot_id}
                                            value={String(l.lot_id)}
                                        >
                                            {l.batch_number}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.lot_id} />
                        </div>
                        <div>
                            <Label htmlFor="adj-qty">Quantity</Label>
                            <Input
                                id="adj-qty"
                                inputMode="decimal"
                                className="mt-1"
                                value={form.data.quantity}
                                onChange={(e) =>
                                    form.setData('quantity', e.target.value)
                                }
                            />
                            <InputError message={form.errors.quantity} />
                        </div>
                        <div>
                            <Label htmlFor="adj-reason">Reason</Label>
                            <Input
                                id="adj-reason"
                                className="mt-1"
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                            />
                            <InputError message={form.errors.reason} />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                )}

                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Material</TableHead>
                                <TableHead className="text-right">
                                    Standard
                                </TableHead>
                                <TableHead className="text-right">
                                    Issued
                                </TableHead>
                                <TableHead className="text-right">
                                    Consumed
                                </TableHead>
                                <TableHead className="text-right">
                                    Returned
                                </TableHead>
                                <TableHead className="text-right">
                                    Wastage
                                </TableHead>
                                <TableHead className="text-right">
                                    Actual
                                </TableHead>
                                <TableHead className="text-right">
                                    Variance
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((l) => (
                                <TableRow
                                    key={l.item_id}
                                    className={cn(l.flag && 'bg-amber-500/5')}
                                >
                                    <TableCell>
                                        <div className="font-medium">
                                            {l.name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {l.code} · {l.unit ?? ''}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {qty(l.standard)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {qty(l.issued)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {qty(l.consumed)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {Number(l.returned) > 0
                                            ? qty(l.returned)
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {Number(l.wastage) > 0
                                            ? qty(l.wastage)
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {qty(l.actual)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <span
                                            className={cn(
                                                l.flag &&
                                                    'font-semibold text-amber-700 dark:text-amber-300',
                                            )}
                                        >
                                            {signed(l.variance)}
                                            {l.variance_percent !== null &&
                                                Number(l.variance) !== 0 && (
                                                    <span className="text-muted-foreground ml-1 text-xs">
                                                        ({l.variance_percent}%)
                                                    </span>
                                                )}
                                        </span>
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow className="bg-muted/30 font-medium">
                                <TableCell>Total</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(totals.standard)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(totals.issued)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(totals.consumed)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(totals.returned)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(totals.wastage)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(totals.actual)}
                                </TableCell>
                                <TableCell />
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>

            {cost && (
                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="inline-flex items-center gap-2 font-semibold">
                            <Calculator className="text-primary size-4" />
                            Cost variance
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {cost.sentence}
                        </p>
                    </div>
                    <div className="grid gap-6 px-5 py-4 lg:grid-cols-3">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm lg:col-span-1">
                            <dt className="text-muted-foreground">
                                Standard cost
                            </dt>
                            <dd className="text-right tabular-nums">
                                {rupees(cost.standard_total)}
                            </dd>
                            <dt className="text-muted-foreground">
                                Actual cost
                            </dt>
                            <dd className="text-right tabular-nums">
                                {rupees(cost.actual_total)}
                            </dd>
                            <dt className="font-medium">Variance</dt>
                            <dd
                                className={cn(
                                    'text-right font-semibold tabular-nums',
                                    Number(cost.variance) > 0
                                        ? 'text-red-700 dark:text-red-300'
                                        : 'text-emerald-700 dark:text-emerald-300',
                                )}
                            >
                                {Number(cost.variance) > 0 ? '+' : ''}
                                {rupees(cost.variance)}
                                {cost.variance_percent !== null &&
                                    ` (${cost.variance_percent}%)`}
                            </dd>
                            {cost.standard_per_unit && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Standard per unit
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {rupees(cost.standard_per_unit, 2)}
                                    </dd>
                                </>
                            )}
                            {cost.actual_per_unit && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Actual per unit
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {rupees(cost.actual_per_unit, 2)}
                                    </dd>
                                </>
                            )}
                        </dl>
                        <div className="lg:col-span-2">
                            <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">
                                Why
                            </p>
                            {cost.reasons.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    Nothing moved the cost off standard.
                                </p>
                            ) : (
                                <ul className="space-y-2 text-sm">
                                    {cost.reasons.map((r) => (
                                        <li
                                            key={r.key}
                                            className="flex items-start justify-between gap-3"
                                        >
                                            <span>
                                                <span className="font-medium">
                                                    {r.label}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    — {r.text}
                                                </span>
                                            </span>
                                            <StatusBadge
                                                variant={
                                                    Number(r.amount) > 0
                                                        ? 'destructive'
                                                        : 'success'
                                                }
                                                className="shrink-0 tabular-nums"
                                            >
                                                {Number(r.amount) > 0
                                                    ? '+'
                                                    : ''}
                                                {rupees(r.amount)}
                                            </StatusBadge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </section>
            )}
        </>
    );
}
