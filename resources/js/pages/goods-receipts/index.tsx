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
import { date } from '@/lib/stock';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/goods-receipts';
import type {
    GoodsReceipt,
    Paginated,
    SelectOption,
    TableState,
} from '@/types';

const STATUS_VARIANT = {
    draft: 'muted',
    received: 'success',
    cancelled: 'destructive',
} as const;

export default function GoodsReceiptIndex({
    receipts,
    table,
    statuses,
    warehouses,
    can,
}: {
    receipts: Paginated<GoodsReceipt>;
    table: TableState;
    statuses: SelectOption[];
    warehouses: SelectOption[];
    can: { create: boolean };
}) {
    const columns: DataTableColumn<GoodsReceipt>[] = [
        {
            key: 'number',
            header: 'GRN',
            sortable: true,
            cell: (r) => r.number,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'received_at',
            header: 'Received',
            sortable: true,
            cell: (r) => date(r.received_at),
        },
        { key: 'vendor', header: 'Vendor', cell: (r) => r.vendor?.name ?? '—' },
        {
            key: 'warehouse',
            header: 'Destination',
            cell: (r) => r.warehouse?.code ?? '—',
        },
        {
            key: 'invoice_ref',
            header: 'Invoice',
            cell: (r) => r.invoice_ref ?? '—',
            defaultHidden: true,
        },
        {
            key: 'lines_count',
            header: 'Lines',
            cell: (r) => r.lines_count ?? 0,
            cellClassName: 'tabular-nums',
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (r) => (
                <StatusBadge variant={STATUS_VARIANT[r.status]}>
                    {r.status}
                </StatusBadge>
            ),
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
            key: 'warehouse',
            label: 'Stores',
            options: warehouses.map((w) => ({
                value: String(w.value),
                label: w.label,
            })),
        },
    ];

    return (
        <>
            <Head title="Goods receipts" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Goods receipts"
                    description="Deliveries booked in. Posting a receipt generates batch numbers and sends stock to quarantine for QC."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New receipt
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={receipts}
                    state={table}
                    baseUrl={index().url}
                    storageKey="goods-receipts"
                    getRowKey={(r) => r.id}
                    rowHref={(r) => show(r.id).url}
                    filters={filters}
                    searchPlaceholder="Search GRN, invoice or vendor…"
                    emptyTitle="No receipts yet"
                    emptyDescription="Book in a delivery to create the first one."
                />
            </div>
        </>
    );
}

GoodsReceiptIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Goods receipts', href: index() },
    ],
};
