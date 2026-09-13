import { Link } from '@inertiajs/react';
import { CircleDollarSign } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { rupees } from '@/lib/intelligence';
import { cn } from '@/lib/utils';
import { show as showOrder } from '@/routes/manufacturing';

export type ProfitabilityTotals = {
    revenue: string;
    manufacturing_charge: string;
    testing: string;
    freight: string;
    raw_material_cost: string;
    packaging_cost: string;
    wastage_cost: string;
    cost: string;
    margin: string;
    margin_percent: string | null;
};

export type ProfitabilityBatch = ProfitabilityTotals & {
    order_id: number;
    number: string;
    product_id: number | null;
    product: string | null;
    completed_at: string | null;
    units: number | null;
    has_terms: boolean;
    material_charge: string;
    other_revenue: string;
    client_material_value: string;
};

export type ProfitabilityProduct = ProfitabilityTotals & {
    product_id: number | null;
    product: string | null;
    batches: number;
};

export type ClientProfitability = ProfitabilityTotals & {
    days: number;
    batches: number;
    products: ProfitabilityProduct[];
    rows: ProfitabilityBatch[];
};

export function MarginBadge({ percent }: { percent: string | null }) {
    if (percent === null) {
        return <StatusBadge variant="muted">no billing</StatusBadge>;
    }
    const n = Number(percent);
    return (
        <StatusBadge
            variant={n < 0 ? 'destructive' : n < 15 ? 'warning' : 'success'}
            className="tabular-nums"
        >
            {n}% margin
        </StatusBadge>
    );
}

export function ClientProfitabilityPanel({
    profitability,
}: {
    profitability: ClientProfitability;
}) {
    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 className="inline-flex items-center gap-2 font-semibold">
                        <CircleDollarSign className="text-primary size-4" />
                        Profitability
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Completed jobs in the last {profitability.days} days:
                        what was charged against what our own material and
                        wastage cost.
                    </p>
                </div>
                <MarginBadge percent={profitability.margin_percent} />
            </div>

            <div className="grid gap-4 px-5 py-4 sm:grid-cols-2 lg:grid-cols-5">
                {[
                    ['Revenue', profitability.revenue],
                    ['Raw material', profitability.raw_material_cost],
                    ['Packaging', profitability.packaging_cost],
                    ['Wastage', profitability.wastage_cost],
                    ['Margin', profitability.margin],
                ].map(([label, value]) => (
                    <div key={label}>
                        <p className="text-muted-foreground text-xs tracking-wide uppercase">
                            {label}
                        </p>
                        <p
                            className={cn(
                                'text-lg font-semibold tabular-nums',
                                label === 'Margin' &&
                                    (Number(value) < 0
                                        ? 'text-red-700 dark:text-red-300'
                                        : 'text-emerald-700 dark:text-emerald-300'),
                            )}
                        >
                            {rupees(value)}
                        </p>
                    </div>
                ))}
            </div>

            {profitability.products.length > 0 && (
                <div className="overflow-x-auto border-t">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">
                                    Batches
                                </TableHead>
                                <TableHead className="text-right">
                                    Revenue
                                </TableHead>
                                <TableHead className="text-right">
                                    Cost
                                </TableHead>
                                <TableHead className="text-right">
                                    Margin
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {profitability.products.map((p) => (
                                <TableRow key={p.product_id ?? 'none'}>
                                    <TableCell className="font-medium">
                                        {p.product ?? 'No product linked'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {p.batches}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rupees(p.revenue)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rupees(p.cost)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rupees(p.margin)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <MarginBadge
                                            percent={p.margin_percent}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            {profitability.rows.length > 0 && (
                <details className="border-t px-5 py-3 text-sm">
                    <summary className="cursor-pointer font-medium">
                        Per batch ({profitability.rows.length})
                    </summary>
                    <ul className="mt-2 divide-y">
                        {profitability.rows.map((r) => (
                            <li
                                key={r.order_id}
                                className="flex flex-wrap items-center justify-between gap-2 py-2"
                            >
                                <span>
                                    <Link
                                        href={showOrder(r.order_id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {r.number}
                                    </Link>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {r.product ?? '—'}
                                        {r.completed_at
                                            ? ` · ${r.completed_at}`
                                            : ''}
                                        {!r.has_terms && ' · no terms recorded'}
                                    </span>
                                </span>
                                <span className="flex items-center gap-3 tabular-nums">
                                    <span>{rupees(r.revenue)}</span>
                                    <span className="text-muted-foreground">
                                        − {rupees(r.cost)}
                                    </span>
                                    <span className="font-medium">
                                        = {rupees(r.margin)}
                                    </span>
                                    <MarginBadge percent={r.margin_percent} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
