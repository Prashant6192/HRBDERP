import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronUp, ShoppingCart } from 'lucide-react';
import { useState } from 'react';
import {
    FacilityFilter,
    StatTile,
} from '@/components/intelligence/facility-filter';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import {
    itemPath,
    OUTLOOK_LABEL,
    OUTLOOK_VARIANT,
    rupees,
    type FacilityChip,
    type ItemOutlook,
} from '@/lib/intelligence';
import { date, qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { reorderAdvice } from '@/routes/purchase';

type Filters = {
    facility: number | null;
    type: string | null;
    all: boolean;
};

function OutlookRow({ row }: { row: ItemOutlook }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <TableRow
                className={cn('cursor-pointer', open && 'bg-muted/40')}
                onClick={() => setOpen((o) => !o)}
            >
                <TableCell>
                    <div className="flex items-center gap-2">
                        {open ? (
                            <ChevronUp className="text-muted-foreground size-4 shrink-0" />
                        ) : (
                            <ChevronDown className="text-muted-foreground size-4 shrink-0" />
                        )}
                        <div>
                            <Link
                                href={itemPath(row.type, row.item_id)}
                                className="font-medium underline-offset-4 hover:underline"
                                onClick={(e) => e.stopPropagation()}
                            >
                                {row.name}
                            </Link>
                            <div className="text-muted-foreground font-mono text-xs">
                                {row.code}
                            </div>
                        </div>
                    </div>
                </TableCell>
                <TableCell>
                    <StatusBadge variant={OUTLOOK_VARIANT[row.status]}>
                        {OUTLOOK_LABEL[row.status]}
                    </StatusBadge>
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    {qty(row.usable)} {row.unit}
                    {Number(row.reserved) > 0 && (
                        <div className="text-muted-foreground text-xs">
                            +{qty(row.reserved)} reserved
                        </div>
                    )}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    {qty(row.upcoming_requirement)}
                    {Number(row.horizon_demand) >
                        Number(row.upcoming_requirement) && (
                        <div className="text-muted-foreground text-xs">
                            ~{qty(row.horizon_demand, 0)} by rate
                        </div>
                    )}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    {Number(row.on_order) > 0 ? qty(row.on_order) : '—'}
                </TableCell>
                <TableCell className="tabular-nums">
                    {row.days_of_cover !== null
                        ? `${row.days_of_cover} days`
                        : row.runs_out_at
                          ? date(row.runs_out_at)
                          : '—'}
                    {row.runs_out_at && row.days_of_cover !== null && (
                        <div className="text-muted-foreground text-xs">
                            {date(row.runs_out_at)}
                        </div>
                    )}
                </TableCell>
                <TableCell className="tabular-nums">
                    {row.order_by ? date(row.order_by) : '—'}
                    <div className="text-muted-foreground text-xs">
                        lead {row.lead_time_days} d
                    </div>
                </TableCell>
                <TableCell className="text-right font-semibold tabular-nums">
                    {Number(row.recommended_quantity) > 0
                        ? `${qty(row.recommended_quantity)} ${row.unit}`
                        : '—'}
                </TableCell>
                <TableCell>
                    {row.vendor?.name ? (
                        <div>
                            <div className="font-medium">{row.vendor.name}</div>
                            <div className="text-muted-foreground text-xs">
                                last {rupees(row.vendor.last_price, 2)}
                                {row.vendor.best_price &&
                                    row.vendor.best_price !==
                                        row.vendor.last_price &&
                                    ` · best ${rupees(row.vendor.best_price, 2)}`}
                            </div>
                        </div>
                    ) : (
                        <span className="text-muted-foreground">
                            no deliveries yet
                        </span>
                    )}
                </TableCell>
            </TableRow>
            {open && (
                <TableRow className="bg-muted/30 hover:bg-muted/30">
                    <TableCell colSpan={9} className="py-4">
                        <div className="grid gap-4 lg:grid-cols-3">
                            <div className="lg:col-span-2">
                                <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">
                                    What the figures say
                                </p>
                                <ul className="space-y-1 text-sm">
                                    {row.sentences.map((line, i) => (
                                        <li
                                            key={i}
                                            className={cn(
                                                'flex items-start gap-2',
                                                i ===
                                                    row.sentences.length - 1 &&
                                                    'font-medium',
                                            )}
                                        >
                                            <span className="bg-primary mt-2 size-1.5 shrink-0 rounded-full" />
                                            <span>{line}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                            <div>
                                <p className="text-muted-foreground mb-2 text-xs font-medium tracking-wide uppercase">
                                    Supplier
                                </p>
                                {row.vendor ? (
                                    <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                        <dt className="text-muted-foreground">
                                            Best price
                                        </dt>
                                        <dd className="tabular-nums">
                                            {rupees(row.vendor.best_price, 2)}
                                            {row.vendor.best_vendor &&
                                                ` (${row.vendor.best_vendor})`}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Last price
                                        </dt>
                                        <dd className="tabular-nums">
                                            {rupees(row.vendor.last_price, 2)}
                                            {row.vendor.last_vendor &&
                                                ` (${row.vendor.last_vendor})`}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Lead time
                                        </dt>
                                        <dd>
                                            {row.vendor.lead_time_days !== null
                                                ? `${row.vendor.lead_time_days} days`
                                                : 'not measured'}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Deliveries
                                        </dt>
                                        <dd>
                                            {row.vendor.deliveries} · last{' '}
                                            {date(row.vendor.last_delivery_at)}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            Pending
                                        </dt>
                                        <dd className="tabular-nums">
                                            {Number(row.on_order) > 0
                                                ? `${qty(row.on_order)} ${row.unit} requested`
                                                : 'nothing outstanding'}
                                        </dd>
                                    </dl>
                                ) : (
                                    <p className="text-muted-foreground text-sm">
                                        No priced deliveries in the last year.
                                        The first receipt with a price will
                                        start the record.
                                    </p>
                                )}
                            </div>
                        </div>
                    </TableCell>
                </TableRow>
            )}
        </>
    );
}

export default function ReorderAdvice({
    rows,
    counts,
    filters,
    facilities,
    settings,
}: {
    rows: ItemOutlook[];
    counts: { order_today: number; order_soon: number; watch: number };
    filters: Filters;
    facilities: FacilityChip[];
    settings: {
        horizon_days: number;
        window_days: number;
        default_lead_time_days: number;
    };
}) {
    const go = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            reorderAdvice().url,
            {
                ...(merged.facility ? { facility: merged.facility } : {}),
                ...(merged.type ? { type: merged.type } : {}),
                ...(merged.all ? { all: 1 } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const value = rows
        .filter(
            (r) => Number(r.recommended_quantity) > 0 && r.vendor?.last_price,
        )
        .reduce(
            (sum, r) =>
                sum +
                Number(r.recommended_quantity) * Number(r.vendor?.last_price),
            0,
        );

    return (
        <>
            <Head title="Reorder advice" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Reorder advice"
                    description={`What to order before it runs out: usable stock against what running and planned production needs, use over the last ${settings.window_days} days, what purchase has already requested, and each supplier's lead time.`}
                    actions={
                        <>
                            <FacilityFilter
                                facilities={facilities}
                                value={filters.facility}
                                onChange={(facility) => go({ facility })}
                            />
                            <Select
                                value={filters.type ?? 'all'}
                                onValueChange={(v) =>
                                    go({ type: v === 'all' ? null : v })
                                }
                            >
                                <SelectTrigger className="min-w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Raw and packaging
                                    </SelectItem>
                                    <SelectItem value="raw_material">
                                        Raw materials
                                    </SelectItem>
                                    <SelectItem value="packaging_material">
                                        Packaging
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                variant={filters.all ? 'secondary' : 'outline'}
                                size="sm"
                                onClick={() => go({ all: !filters.all })}
                            >
                                {filters.all
                                    ? 'Showing every material'
                                    : 'Show every material'}
                            </Button>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Order today"
                        value={counts.order_today}
                        tone={counts.order_today > 0 ? 'danger' : 'success'}
                        hint="would run out before a delivery could land"
                    />
                    <StatTile
                        label="Order this week"
                        value={counts.order_soon}
                        tone={counts.order_soon > 0 ? 'warning' : 'success'}
                        hint="the order date falls within seven days"
                    />
                    <StatTile
                        label="Watch"
                        value={counts.watch}
                        hint="low, or an order is due later in the month"
                    />
                    <StatTile
                        label="Purchase value"
                        value={rupees(value)}
                        hint="recommended quantities at each supplier's last price"
                    />
                </div>

                <div className="bg-card overflow-x-auto rounded-2xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Material</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Usable
                                </TableHead>
                                <TableHead className="text-right">
                                    Upcoming need
                                </TableHead>
                                <TableHead className="text-right">
                                    Requested
                                </TableHead>
                                <TableHead>Cover</TableHead>
                                <TableHead>Order by</TableHead>
                                <TableHead className="text-right">
                                    Recommended
                                </TableHead>
                                <TableHead>Supplier</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={9}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        <ShoppingCart className="mx-auto mb-2 size-6" />
                                        Nothing needs ordering. Every material
                                        is covered for its lead time and the
                                        next {settings.horizon_days} days of
                                        production.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.map((row) => (
                                    <OutlookRow key={row.item_id} row={row} />
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                <p className="text-muted-foreground text-xs">
                    Usable is stock in the stores that QC has released and that
                    is not reserved for a running batch. Upcoming need is what
                    approved batches still lack plus what checked plans will
                    take. A material with no lead time on its card or its
                    supplier is assumed to take{' '}
                    {settings.default_lead_time_days} days.
                </p>
            </div>
        </>
    );
}

ReorderAdvice.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Reorder advice', href: reorderAdvice() },
    ],
};
