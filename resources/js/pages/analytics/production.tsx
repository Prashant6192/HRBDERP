import { Head, Link, router } from '@inertiajs/react';
import { BarChart3, TrendingDown, TrendingUp } from 'lucide-react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    FacilityFilter,
    StatTile,
} from '@/components/intelligence/facility-filter';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { itemPath, type FacilityChip } from '@/lib/intelligence';
import { date, qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { production } from '@/routes/analytics';
import { show as showOrder } from '@/routes/manufacturing';

type Trend = {
    item_id: number;
    code: string;
    name: string;
    unit: string | null;
    batches: number;
    standard: string;
    actual: string;
    wastage: string;
    variance_percent: string;
    sentence: string;
    per_batch: {
        order_id: number;
        number: string;
        completed_at: string;
        standard: string;
        actual: string;
        variance_percent: string | null;
    }[];
};

type Yield = {
    product_id: number | null;
    code: string | null;
    name: string;
    unit: string | null;
    batches: number;
    planned_quantity: string;
    output_quantity: string;
    planned_units: number;
    output_units: number;
    yield_percent: string;
    unit_variance: number;
    below_target: boolean;
    sentence: string;
    per_batch: {
        order_id: number;
        number: string;
        completed_at: string | null;
        planned_units: number | null;
        output_units: number | null;
        yield_percent: string | null;
    }[];
};

type Filters = { facility: number | null; batches: number; days: number };

export default function ProductionAnalytics({
    trends,
    yields,
    filters,
    facilities,
    thresholds,
}: {
    trends: Trend[];
    yields: Yield[];
    filters: Filters;
    facilities: FacilityChip[];
    thresholds: { variance_percent: number; yield_floor_percent: number };
}) {
    const go = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            production().url,
            {
                batches: merged.batches,
                days: merged.days,
                ...(merged.facility ? { facility: merged.facility } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const over = trends.filter(
        (t) => Number(t.variance_percent) >= thresholds.variance_percent,
    );
    const below = yields.filter((y) => y.below_target);
    const chart = trends.slice(0, 12).map((t) => ({
        name: t.name.length > 18 ? `${t.name.slice(0, 17)}…` : t.name,
        variance: Number(t.variance_percent),
    }));

    return (
        <>
            <Head title="Production analytics" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Production analytics"
                    description="Material consumption against the recipe and yield against plan, across batches, so trends show before they become losses."
                    actions={
                        <>
                            <FacilityFilter
                                facilities={facilities}
                                value={filters.facility}
                                onChange={(facility) => go({ facility })}
                            />
                            <div className="flex gap-1">
                                {[3, 6, 12, 24].map((n) => (
                                    <Button
                                        key={n}
                                        size="sm"
                                        variant={
                                            filters.batches === n
                                                ? 'secondary'
                                                : 'outline'
                                        }
                                        onClick={() => go({ batches: n })}
                                    >
                                        last {n}
                                    </Button>
                                ))}
                            </div>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Materials over standard"
                        value={over.length}
                        tone={over.length > 0 ? 'warning' : 'success'}
                        hint={`more than ${thresholds.variance_percent}% above the recipe over the last ${filters.batches} batches`}
                    />
                    <StatTile
                        label="Materials tracked"
                        value={trends.length}
                        hint="with completed batches on record"
                    />
                    <StatTile
                        label="Products below target"
                        value={below.length}
                        tone={below.length > 0 ? 'danger' : 'success'}
                        hint={`yield under ${thresholds.yield_floor_percent}% in the last ${filters.days} days`}
                    />
                    <StatTile
                        label="Products made"
                        value={yields.length}
                        hint={`in the last ${filters.days} days`}
                    />
                </div>

                <section className="bg-card rounded-2xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="inline-flex items-center gap-2 font-semibold">
                            <BarChart3 className="text-primary size-4" />
                            Consumption against standard
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Actual use (consumed less returned) against the
                            recipe, per material, over the last{' '}
                            {filters.batches} batches. Positive is over.
                        </p>
                    </div>
                    {chart.length > 0 && (
                        <div className="h-64 px-2 pt-4">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart
                                    data={chart}
                                    margin={{ left: 8, right: 8 }}
                                >
                                    <CartesianGrid
                                        strokeDasharray="3 3"
                                        vertical={false}
                                        className="stroke-border"
                                    />
                                    <XAxis
                                        dataKey="name"
                                        tick={{ fontSize: 11 }}
                                        interval={0}
                                        angle={-20}
                                        textAnchor="end"
                                        height={60}
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11 }}
                                        unit="%"
                                        width={44}
                                    />
                                    <Tooltip
                                        formatter={(v) => [
                                            `${String(v)}%`,
                                            'Variance',
                                        ]}
                                        contentStyle={{ fontSize: 12 }}
                                    />
                                    <ReferenceLine
                                        y={thresholds.variance_percent}
                                        stroke="#d97706"
                                        strokeDasharray="4 4"
                                    />
                                    <ReferenceLine y={0} stroke="#888" />
                                    <Bar
                                        dataKey="variance"
                                        radius={[4, 4, 0, 0]}
                                        fill="var(--primary)"
                                    />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Material</TableHead>
                                    <TableHead className="text-right">
                                        Batches
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Standard
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Actual
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Wastage
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Variance
                                    </TableHead>
                                    <TableHead>Trend</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {trends.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="text-muted-foreground py-10 text-center"
                                        >
                                            No completed batches yet.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    trends.map((t) => {
                                        const v = Number(t.variance_percent);
                                        const flag =
                                            v >= thresholds.variance_percent;
                                        return (
                                            <TableRow
                                                key={t.item_id}
                                                className={cn(
                                                    flag && 'bg-amber-500/5',
                                                )}
                                            >
                                                <TableCell>
                                                    <Link
                                                        href={itemPath(
                                                            'raw_material',
                                                            t.item_id,
                                                        )}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {t.name}
                                                    </Link>
                                                    <div className="text-muted-foreground max-w-md text-xs">
                                                        {t.sentence}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {t.batches}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(t.standard)} {t.unit}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(t.actual)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {Number(t.wastage) > 0
                                                        ? qty(t.wastage)
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <StatusBadge
                                                        variant={
                                                            flag
                                                                ? 'warning'
                                                                : v < 0
                                                                  ? 'info'
                                                                  : 'success'
                                                        }
                                                        className="tabular-nums"
                                                    >
                                                        {v > 0 ? (
                                                            <TrendingUp className="mr-1 size-3" />
                                                        ) : v < 0 ? (
                                                            <TrendingDown className="mr-1 size-3" />
                                                        ) : null}
                                                        {v > 0 ? '+' : ''}
                                                        {v}%
                                                    </StatusBadge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-end gap-0.5">
                                                        {[...t.per_batch]
                                                            .reverse()
                                                            .map((b) => {
                                                                const bv =
                                                                    Number(
                                                                        b.variance_percent ??
                                                                            0,
                                                                    );
                                                                return (
                                                                    <Link
                                                                        key={
                                                                            b.order_id
                                                                        }
                                                                        href={showOrder(
                                                                            b.order_id,
                                                                        )}
                                                                        title={`${b.number} · ${date(b.completed_at)} · ${bv > 0 ? '+' : ''}${bv}%`}
                                                                        className={cn(
                                                                            'w-2 rounded-sm',
                                                                            bv >=
                                                                                thresholds.variance_percent
                                                                                ? 'bg-amber-500'
                                                                                : bv >
                                                                                    0
                                                                                  ? 'bg-primary/60'
                                                                                  : 'bg-emerald-500/60',
                                                                        )}
                                                                        style={{
                                                                            height: `${Math.min(28, 6 + Math.abs(bv) * 2)}px`,
                                                                        }}
                                                                    />
                                                                );
                                                            })}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <section className="bg-card rounded-2xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="font-semibold">Yield by product</h2>
                            <p className="text-muted-foreground text-sm">
                                Planned against actual output; the variance is
                                the leakage.
                            </p>
                        </div>
                        <div className="flex gap-1">
                            {[30, 90, 180, 365].map((d) => (
                                <Button
                                    key={d}
                                    size="sm"
                                    variant={
                                        filters.days === d
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    onClick={() => go({ days: d })}
                                >
                                    {d} d
                                </Button>
                            ))}
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Product</TableHead>
                                    <TableHead className="text-right">
                                        Batches
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Planned
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Produced
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Yield
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Variance
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {yields.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="text-muted-foreground py-10 text-center"
                                        >
                                            No batch completed in the last{' '}
                                            {filters.days} days.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    yields.map((y) => (
                                        <TableRow
                                            key={y.product_id ?? 'none'}
                                            className={cn(
                                                y.below_target &&
                                                    'bg-red-500/5',
                                            )}
                                        >
                                            <TableCell>
                                                <div className="font-medium">
                                                    {y.name}
                                                </div>
                                                <div className="text-muted-foreground max-w-md text-xs">
                                                    {y.sentence}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {y.batches}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {y.planned_units > 0
                                                    ? `${y.planned_units.toLocaleString('en-IN')} units`
                                                    : `${qty(y.planned_quantity)} ${y.unit ?? ''}`}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {y.output_units > 0
                                                    ? `${y.output_units.toLocaleString('en-IN')} units`
                                                    : `${qty(y.output_quantity)} ${y.unit ?? ''}`}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <StatusBadge
                                                    variant={
                                                        y.below_target
                                                            ? 'destructive'
                                                            : 'success'
                                                    }
                                                    className="tabular-nums"
                                                >
                                                    {y.yield_percent}%
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {y.planned_units > 0
                                                    ? `${y.unit_variance > 0 ? '−' : ''}${Math.abs(y.unit_variance).toLocaleString('en-IN')} units`
                                                    : '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>
        </>
    );
}

ProductionAnalytics.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Production analytics', href: production() },
    ],
};
