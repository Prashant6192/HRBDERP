import { Link } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { ALERT_LABEL } from '@/lib/stock';
import type {
    OutputPoint,
    ReceivingPoint,
    StockAlertLevel,
    StoreLevels,
} from '@/types';

const LEVEL_COLOUR: Record<StockAlertLevel, string> = {
    healthy: 'var(--chart-2)',
    moderate: 'var(--chart-4)',
    low: 'var(--chart-1)',
    critical: 'var(--chart-5)',
    out_of_stock: 'var(--muted-foreground)',
};

const LEVEL_ORDER: StockAlertLevel[] = [
    'healthy',
    'moderate',
    'low',
    'critical',
    'out_of_stock',
];

const tooltipStyle = {
    borderRadius: 10,
    border: '1px solid var(--border)',
    background: 'var(--popover)',
    color: 'var(--popover-foreground)',
    fontSize: 12,
};

function shortDate(iso: unknown): string {
    if (typeof iso !== 'string') {
        return '';
    }

    const d = new Date(iso);
    return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short' });
}

/** Tooltip labels arrive untyped; only a string is a label worth showing. */
function asLabel(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

/** One store's items by alert level, as a donut with its counts beside it. */
export function StoreCard({ store }: { store: StoreLevels }) {
    const data = LEVEL_ORDER.map((level) => ({
        level,
        name: ALERT_LABEL[level],
        value: store.counts[level] ?? 0,
    })).filter((d) => d.value > 0);

    const attention =
        (store.counts.low ?? 0) +
        (store.counts.critical ?? 0) +
        (store.counts.out_of_stock ?? 0);

    return (
        <Link
            href={store.href}
            className="bg-card hover:border-primary/40 block rounded-2xl border p-5 transition-colors"
        >
            <div className="flex items-start justify-between">
                <div>
                    <h3 className="font-semibold">{store.label}</h3>
                    <p className="text-muted-foreground text-xs">
                        {store.code ?? 'Not configured'} · {store.items} item
                        {store.items === 1 ? '' : 's'}
                    </p>
                </div>
                {attention > 0 ? (
                    <span className="rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-medium text-red-700 dark:text-red-300">
                        {attention} to watch
                    </span>
                ) : store.items > 0 ? (
                    <span className="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                        Healthy
                    </span>
                ) : null}
            </div>

            <div className="mt-3 flex items-center gap-4">
                <div className="h-28 w-28 shrink-0">
                    {data.length === 0 ? (
                        <div className="text-muted-foreground flex h-full items-center justify-center rounded-full border border-dashed text-xs">
                            Empty
                        </div>
                    ) : (
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={data}
                                    dataKey="value"
                                    nameKey="name"
                                    innerRadius={36}
                                    outerRadius={54}
                                    paddingAngle={2}
                                    stroke="none"
                                >
                                    {data.map((d) => (
                                        <Cell
                                            key={d.level}
                                            fill={LEVEL_COLOUR[d.level]}
                                        />
                                    ))}
                                </Pie>
                                <Tooltip contentStyle={tooltipStyle} />
                            </PieChart>
                        </ResponsiveContainer>
                    )}
                </div>
                <ul className="flex-1 space-y-1 text-xs">
                    {LEVEL_ORDER.map((level) => (
                        <li
                            key={level}
                            className="flex items-center justify-between gap-2"
                        >
                            <span className="flex items-center gap-1.5">
                                <span
                                    className="size-2 rounded-full"
                                    style={{ background: LEVEL_COLOUR[level] }}
                                />
                                {ALERT_LABEL[level]}
                            </span>
                            <span className="font-medium tabular-nums">
                                {store.counts[level] ?? 0}
                            </span>
                        </li>
                    ))}
                </ul>
            </div>
        </Link>
    );
}

/** Deliveries booked in and QC decisions, day by day. */
export function ReceivingChart({ data }: { data: ReceivingPoint[] }) {
    const total = data.reduce((s, d) => s + d.received, 0);

    if (total === 0 && data.every((d) => d.approved + d.rejected === 0)) {
        return (
            <p className="text-muted-foreground flex h-56 items-center justify-center text-sm">
                No deliveries or QC decisions in this period.
            </p>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={224}>
            <ComposedChart
                data={data}
                margin={{ top: 8, right: 8, left: -18, bottom: 0 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="var(--border)"
                    vertical={false}
                />
                <XAxis
                    dataKey="date"
                    tickFormatter={shortDate}
                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                    tickLine={false}
                    axisLine={false}
                    minTickGap={24}
                />
                <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip
                    contentStyle={tooltipStyle}
                    labelFormatter={(v) => shortDate(v)}
                />
                <Bar
                    dataKey="received"
                    name="Lines received"
                    fill="var(--chart-2)"
                    radius={[4, 4, 0, 0]}
                />
                <Line
                    type="monotone"
                    dataKey="approved"
                    name="QC approved"
                    stroke="var(--chart-1)"
                    strokeWidth={2}
                    dot={false}
                />
                <Line
                    type="monotone"
                    dataKey="rejected"
                    name="QC rejected"
                    stroke="var(--chart-5)"
                    strokeWidth={2}
                    dot={false}
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}

/** Finished units per week. */
export function OutputChart({ data }: { data: OutputPoint[] }) {
    if (data.every((d) => d.units === 0 && d.batches === 0)) {
        return (
            <p className="text-muted-foreground flex h-56 items-center justify-center text-sm">
                No batches completed in the last 12 weeks.
            </p>
        );
    }

    return (
        <ResponsiveContainer width="100%" height={224}>
            <BarChart
                data={data}
                margin={{ top: 8, right: 8, left: -18, bottom: 0 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke="var(--border)"
                    vertical={false}
                />
                <XAxis
                    dataKey="week"
                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                    tickLine={false}
                    axisLine={false}
                />
                <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: 'var(--muted-foreground)' }}
                    tickLine={false}
                    axisLine={false}
                />
                <Tooltip
                    contentStyle={tooltipStyle}
                    formatter={(value, name, item) => [
                        `${Number(value).toLocaleString()} units · ${(item.payload as OutputPoint).batches} batch${(item.payload as OutputPoint).batches === 1 ? '' : 'es'}`,
                        asLabel(name),
                    ]}
                    labelFormatter={(v, payload) => {
                        const p = payload?.[0]?.payload as
                            | OutputPoint
                            | undefined;
                        return p
                            ? `${asLabel(v)} · from ${shortDate(p.start)}`
                            : asLabel(v);
                    }}
                />
                <Bar
                    dataKey="units"
                    name="Units packed"
                    fill="var(--chart-1)"
                    radius={[6, 6, 0, 0]}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}
