import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck, Clock } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { date, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/qc';
import type {
    Paginated,
    QcInspection,
    SelectOption,
    TableState,
} from '@/types';

export default function QcIndex({
    inspections,
    table,
    statuses,
    counts,
}: {
    inspections: Paginated<QcInspection>;
    table: TableState;
    statuses: SelectOption[];
    counts: { open: number; approved_today: number };
}) {
    const columns: DataTableColumn<QcInspection>[] = [
        {
            key: 'number',
            header: 'QC ref',
            sortable: true,
            cell: (r) => r.number,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'batch',
            header: 'Batch',
            cell: (r) => (
                <span className="font-mono">{r.lot?.batch_number}</span>
            ),
        },
        {
            key: 'item',
            header: 'Item',
            cell: (r) => (
                <div>
                    <div className="font-medium">{r.item?.code}</div>
                    <div className="text-muted-foreground text-xs">
                        {r.item?.name}
                    </div>
                </div>
            ),
        },
        {
            key: 'quantity',
            header: 'Quantity',
            cell: (r) => `${qty(r.quantity)} ${r.item?.stock_uom?.code ?? ''}`,
            cellClassName: 'tabular-nums whitespace-nowrap',
        },
        {
            key: 'received',
            header: 'Received',
            cell: (r) => date(r.lot?.received_at),
        },
        {
            key: 'destination',
            header: 'Release to',
            cell: (r) => r.destination_warehouse?.code ?? '—',
            defaultHidden: true,
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (r) => (
                <StatusBadge variant={QC_VARIANT[r.status]}>
                    {QC_LABEL[r.status]}
                </StatusBadge>
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-20',
            cell: (r) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(r.id)}>
                        {r.status === 'pending' ? 'Inspect' : 'Open'}
                    </Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'status',
            label: 'Status',
            options: statuses.map((s) => ({
                value: String(s.value),
                label: s.label,
            })),
        },
    ];

    return (
        <>
            <Head title="Quality control" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Quality control"
                    description="Every received batch waits here until QC releases it to the store."
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:max-w-lg">
                    <div className="bg-card rounded-xl border p-5">
                        <div className="text-muted-foreground flex items-center gap-2 text-sm">
                            <Clock className="size-4" /> Awaiting decision
                        </div>
                        <p className="mt-2 text-3xl font-semibold tabular-nums">
                            {counts.open}
                        </p>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <div className="text-muted-foreground flex items-center gap-2 text-sm">
                            <ClipboardCheck className="size-4" /> Approved today
                        </div>
                        <p className="mt-2 text-3xl font-semibold tabular-nums">
                            {counts.approved_today}
                        </p>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    rows={inspections}
                    state={table}
                    baseUrl={index().url}
                    storageKey="qc"
                    getRowKey={(r) => r.id}
                    rowHref={(r) => show(r.id).url}
                    filters={filters}
                    searchPlaceholder="Search QC ref, batch or item…"
                    emptyTitle="Nothing waiting"
                    emptyDescription="Batches appear here when a goods receipt is posted for an item that requires QC."
                />
            </div>
        </>
    );
}

QcIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Quality control', href: index() },
    ],
};
