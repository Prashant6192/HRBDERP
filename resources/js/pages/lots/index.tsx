import { Head, Link } from '@inertiajs/react';
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
import { index, show } from '@/routes/lots';
import type {
    InventoryLot,
    Paginated,
    SelectOption,
    TableState,
} from '@/types';

export default function LotIndex({
    lots,
    table,
    statuses,
    types,
}: {
    lots: Paginated<InventoryLot>;
    table: TableState;
    statuses: SelectOption[];
    types: SelectOption[];
}) {
    const columns: DataTableColumn<InventoryLot>[] = [
        {
            key: 'batch_number',
            header: 'Batch',
            sortable: true,
            cell: (r) => (
                <span className="font-mono font-medium">{r.batch_number}</span>
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
            key: 'on_hand',
            header: 'On hand',
            cell: (r) =>
                `${qty(r.on_hand ?? '0')} ${r.item?.stock_uom?.code ?? ''}`,
            cellClassName: 'tabular-nums whitespace-nowrap',
        },
        {
            key: 'received_at',
            header: 'Received',
            sortable: true,
            cell: (r) => date(r.received_at),
        },
        {
            key: 'expiry_at',
            header: 'Expiry',
            sortable: true,
            cell: (r) => date(r.expiry_at),
        },
        {
            key: 'vendor',
            header: 'Vendor',
            cell: (r) => r.vendor?.name ?? '—',
            defaultHidden: true,
        },
        {
            key: 'qc_status',
            header: 'QC',
            sortable: true,
            cell: (r) => (
                <StatusBadge variant={QC_VARIANT[r.qc_status]}>
                    {QC_LABEL[r.qc_status]}
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
            key: 'qc_status',
            label: 'QC status',
            options: statuses.map((s) => ({
                value: String(s.value),
                label: s.label,
            })),
        },
        {
            key: 'type',
            label: 'Types',
            options: types.map((t) => ({
                value: String(t.value),
                label: t.label,
            })),
        },
    ];

    return (
        <>
            <Head title="Batches" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Batches"
                    description="Every lot the ERP has ever held, with where it stands."
                />
                <DataTable
                    columns={columns}
                    rows={lots}
                    state={table}
                    baseUrl={index().url}
                    storageKey="lots"
                    getRowKey={(r) => r.id}
                    rowHref={(r) => show(r.id).url}
                    filters={filters}
                    searchPlaceholder="Search batch, supplier ref or item…"
                    emptyTitle="No batches yet"
                    emptyDescription="Batches are created when a goods receipt is posted."
                />
            </div>
        </>
    );
}

LotIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Batches', href: index() },
    ],
};
