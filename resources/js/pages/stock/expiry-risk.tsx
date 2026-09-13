import { Head, Link, router } from '@inertiajs/react';
import { CalendarX2 } from 'lucide-react';
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
import {
    itemPath,
    RISK_LABEL,
    RISK_VARIANT,
    rupees,
    type ExpiryRiskReport,
    type FacilityChip,
} from '@/lib/intelligence';
import { date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { show as showLot } from '@/routes/lots';
import { expiryRisk } from '@/routes/stock';

const WINDOWS = [30, 60, 90, 180, 365];

export default function ExpiryRisk({
    report,
    filters,
    facilities,
}: {
    report: ExpiryRiskReport;
    filters: { facility: number | null; days: number };
    facilities: FacilityChip[];
}) {
    const go = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            expiryRisk().url,
            {
                days: merged.days,
                ...(merged.facility ? { facility: merged.facility } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const high = report.rows.filter(
        (r) => r.level === 'high' || r.level === 'expired',
    ).length;

    return (
        <>
            <Head title="Expiry risk" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Expiry risk"
                    description="Will each batch be used before it expires? Expected use is the material's daily rate over the days left, after the batches that expire sooner have been used first."
                    actions={
                        <>
                            <FacilityFilter
                                facilities={facilities}
                                value={filters.facility}
                                onChange={(facility) => go({ facility })}
                            />
                            <div className="flex gap-1">
                                {WINDOWS.map((d) => (
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
                                        {d} days
                                    </Button>
                                ))}
                            </div>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Inventory at risk"
                        value={rupees(report.at_risk_value)}
                        tone={
                            Number(report.at_risk_value) > 0
                                ? 'danger'
                                : 'success'
                        }
                        hint="stock unlikely to be used before it expires"
                    />
                    <StatTile
                        label="Batches at risk"
                        value={report.at_risk_lots}
                        tone={report.at_risk_lots > 0 ? 'warning' : 'success'}
                        hint={`${high} of them high risk or already expired`}
                    />
                    <StatTile
                        label="Expiring in window"
                        value={report.lots}
                        hint={`batches expiring within ${report.window_days} days`}
                    />
                    <StatTile
                        label="Will be used in time"
                        value={report.lots - report.at_risk_lots}
                        tone="success"
                        hint="at the current rate of use"
                    />
                </div>

                <div className="bg-card overflow-x-auto rounded-2xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Batch</TableHead>
                                <TableHead>Material</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    Expected use
                                </TableHead>
                                <TableHead>Coverage</TableHead>
                                <TableHead className="text-right">
                                    At risk
                                </TableHead>
                                <TableHead>Risk</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.rows.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        <CalendarX2 className="mx-auto mb-2 size-6" />
                                        No batch in stock expires within{' '}
                                        {report.window_days} days.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                report.rows.map((r) => (
                                    <TableRow key={r.lot_id}>
                                        <TableCell>
                                            <Link
                                                href={showLot(r.lot_id)}
                                                className="font-mono font-medium underline-offset-4 hover:underline"
                                            >
                                                {r.batch_number}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">
                                                {r.client_owned &&
                                                    'client-owned · '}
                                                {r.in_quarantine &&
                                                    'in quarantine'}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Link
                                                href={itemPath(
                                                    r.type,
                                                    r.item_id,
                                                )}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {r.name}
                                            </Link>
                                            <div className="text-muted-foreground max-w-md text-xs">
                                                {r.sentence}
                                            </div>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {date(r.expiry_at)}
                                            <div className="text-muted-foreground text-xs">
                                                {r.days_left === 0
                                                    ? 'expired'
                                                    : `${r.days_left} days`}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(r.on_hand)} {r.unit ?? ''}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(r.expected_use)}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <div className="bg-muted h-2 w-20 overflow-hidden rounded-full">
                                                    <div
                                                        className={`h-full ${
                                                            Number(
                                                                r.coverage_percent,
                                                            ) >= 100
                                                                ? 'bg-emerald-500'
                                                                : Number(
                                                                        r.coverage_percent,
                                                                    ) >= 50
                                                                  ? 'bg-amber-500'
                                                                  : 'bg-red-500'
                                                        }`}
                                                        style={{
                                                            width: `${Math.min(100, Number(r.coverage_percent))}%`,
                                                        }}
                                                    />
                                                </div>
                                                <span className="text-xs tabular-nums">
                                                    {qty(r.coverage_percent, 1)}
                                                    %
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.at_risk_value === null
                                                ? `${qty(r.at_risk_quantity)} ${r.unit ?? ''}`
                                                : rupees(r.at_risk_value)}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                variant={RISK_VARIANT[r.level]}
                                            >
                                                {RISK_LABEL[r.level]}
                                            </StatusBadge>
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

ExpiryRisk.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Expiry risk', href: expiryRisk() },
    ],
};
