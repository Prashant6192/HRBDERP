import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/transfers';
import type {
    Paginated,
    SelectOption,
    StockTransferRow,
    StockTransferStatus,
    TableState,
} from '@/types';

export const TRANSFER_VARIANT: Record<
    StockTransferStatus,
    'success' | 'warning' | 'destructive' | 'muted' | 'info'
> = {
    draft: 'muted',
    requested: 'info',
    approved: 'warning',
    packed: 'warning',
    dispatched: 'info',
    in_transit: 'info',
    partially_received: 'warning',
    received: 'success',
    discrepancy: 'destructive',
    rejected: 'muted',
    cancelled: 'muted',
};

export default function TransfersIndex({
    transfers,
    table,
    statuses,
    facilities,
    can,
}: {
    transfers: Paginated<StockTransferRow>;
    table: TableState;
    statuses: SelectOption[];
    facilities: SelectOption[];
    can: { create: boolean };
}) {
    const columns: DataTableColumn<StockTransferRow>[] = [
        {
            key: 'number',
            header: 'Number',
            sortable: true,
            cell: (r) => r.number,
            cellClassName: 'font-medium',
        },
        {
            key: 'from',
            header: 'From',
            cell: (r) => (
                <div>
                    <div>{r.source_facility?.name}</div>
                    <div className="text-muted-foreground font-mono text-xs">
                        {r.source_store?.code}
                    </div>
                </div>
            ),
        },
        {
            key: 'to',
            header: 'To',
            cell: (r) => (
                <div>
                    <div>{r.destination_facility?.name}</div>
                    <div className="text-muted-foreground font-mono text-xs">
                        {r.destination_store?.code}
                    </div>
                </div>
            ),
        },
        {
            key: 'lines_count',
            header: 'Lines',
            cell: (r) => r.lines_count,
            cellClassName: 'tabular-nums',
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (r) => (
                <StatusBadge variant={TRANSFER_VARIANT[r.status]}>
                    {statuses.find((s) => s.value === r.status)?.label ??
                        r.status}
                </StatusBadge>
            ),
        },
        {
            key: 'expected_at',
            header: 'Expected',
            sortable: true,
            cell: (r) => r.expected_at ?? '—',
        },
        {
            key: 'requester',
            header: 'Raised by',
            cell: (r) => r.requester?.name ?? '—',
            defaultHidden: true,
        },
        {
            key: 'created_at',
            header: 'Raised',
            sortable: true,
            cell: (r) => new Date(r.created_at).toLocaleDateString('en-IN'),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (r) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(r.id)}>Open</Link>
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
        {
            key: 'facility',
            label: 'Facility',
            options: facilities.map((f) => ({
                value: String(f.value),
                label: f.label,
            })),
        },
        {
            key: 'direction',
            label: 'Direction',
            options: [
                { value: 'in', label: 'Inbound' },
                { value: 'out', label: 'Outbound' },
            ],
        },
    ];

    return (
        <>
            <Head title="Stock Transfers" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Stock Transfers"
                    description="Stock on the move between facilities. It leaves the source on dispatch and reaches the destination only when received."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New transfer
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={transfers}
                    state={table}
                    baseUrl={index().url}
                    storageKey="transfers"
                    getRowKey={(r) => r.id}
                    rowHref={(r) => show(r.id).url}
                    filters={filters}
                    searchPlaceholder="Search number or reason…"
                    emptyTitle="No transfers"
                    emptyDescription="Raise a transfer when one facility needs stock another one holds."
                />
            </div>
        </>
    );
}

TransfersIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stock Transfers', href: index() },
    ],
};
