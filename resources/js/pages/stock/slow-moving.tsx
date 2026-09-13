import { Head, Link, router } from '@inertiajs/react';
import { PackageX } from 'lucide-react';
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
    rupees,
    type FacilityChip,
    type SlowMovingReport,
} from '@/lib/intelligence';
import { date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { show as showLot } from '@/routes/lots';
import { slowMoving } from '@/routes/stock';

const TYPE_LABEL: Record<string, string> = {
    raw_material: 'Raw material',
    packaging_material: 'Packaging',
    finished_good: 'Finished good',
    semi_finished: 'Semi-finished',
    consumable: 'Consumable',
};

function bucketVariant(days: number): 'destructive' | 'warning' | 'info' {
    if (days >= 180) return 'destructive';
    if (days >= 90) return 'warning';
    return 'info';
}

export default function SlowMovingStock({
    report,
    thresholds,
    filters,
    facilities,
}: {
    report: SlowMovingReport;
    thresholds: number[];
    filters: { facility: number | null; days: number };
    facilities: FacilityChip[];
}) {
    const go = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        router.get(
            slowMoving().url,
            {
                days: merged.days,
                ...(merged.facility ? { facility: merged.facility } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };

    const ascending = [...thresholds].sort((a, b) => a - b);

    return (
        <>
            <Head title="Slow-moving stock" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Slow-moving stock"
                    description="Batches that have not been issued for a while, and the working capital sitting in them. Idle is counted from the last issue, or from arrival when a batch has never been touched."
                    actions={
                        <>
                            <FacilityFilter
                                facilities={facilities}
                                value={filters.facility}
                                onChange={(facility) => go({ facility })}
                            />
                            <div className="flex gap-1">
                                {ascending.map((d) => (
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
                                        {d}+ days
                                    </Button>
                                ))}
                            </div>
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <StatTile
                        label="Blocked working capital"
                        value={rupees(report.total_value)}
                        tone={
                            Number(report.total_value) > 0
                                ? 'warning'
                                : 'success'
                        }
                        hint={`${report.rows.length} batches idle ${filters.days}+ days`}
                    />
                    {report.buckets.map((b) => (
                        <StatTile
                            key={b.days}
                            label={b.label}
                            value={rupees(b.value)}
                            hint={`${b.lots} ${b.lots === 1 ? 'batch' : 'batches'}`}
                            tone={
                                b.lots === 0
                                    ? 'default'
                                    : b.days >= 180
                                      ? 'danger'
                                      : b.days >= 90
                                        ? 'warning'
                                        : 'default'
                            }
                        />
                    ))}
                </div>

                <div className="bg-card overflow-x-auto rounded-2xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Batch</TableHead>
                                <TableHead>Material</TableHead>
                                <TableHead>Store</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    Value
                                </TableHead>
                                <TableHead>Idle since</TableHead>
                                <TableHead>Idle</TableHead>
                                <TableHead>Expiry</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.rows.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        <PackageX className="mx-auto mb-2 size-6" />
                                        Nothing has sat idle for {filters.days}{' '}
                                        days or more.
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
                                            {!r.ever_issued && (
                                                <div className="text-muted-foreground text-xs">
                                                    never issued
                                                </div>
                                            )}
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
                                            <div className="text-muted-foreground text-xs">
                                                {r.code} ·{' '}
                                                {TYPE_LABEL[r.type] ?? r.type}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {r.warehouse.name}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(r.on_hand)} {r.unit ?? ''}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {r.value === null
                                                ? '—'
                                                : rupees(r.value)}
                                        </TableCell>
                                        <TableCell>
                                            {date(r.idle_since)}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                variant={bucketVariant(
                                                    r.idle_days,
                                                )}
                                            >
                                                {r.idle_days} days
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell>
                                            {date(r.expiry_at)}
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

SlowMovingStock.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Slow-moving stock', href: slowMoving() },
    ],
};
