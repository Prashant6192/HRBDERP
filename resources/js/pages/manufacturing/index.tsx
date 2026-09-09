import { Head, Link } from '@inertiajs/react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { MO_STATUS_LABEL, MO_STATUS_VARIANT } from '@/lib/planning';
import { date, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/manufacturing';
import { show as showPlan } from '@/routes/plans';
import type {
    ManufacturingOrder,
    Paginated,
    SelectOption,
    TableState,
} from '@/types';

export default function ManufacturingIndex({
    orders,
    table,
    statuses,
}: {
    orders: Paginated<ManufacturingOrder>;
    table: TableState;
    statuses: SelectOption[];
}) {
    const columns: DataTableColumn<ManufacturingOrder>[] = [
        {
            key: 'number',
            header: 'Order',
            sortable: true,
            cell: (o) => o.number,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'formula',
            header: 'Formula / product',
            cell: (o) => (
                <div>
                    <div className="font-medium">{o.formula?.name}</div>
                    <div className="text-muted-foreground text-xs">
                        {o.product?.name ?? 'No product linked'}
                        {o.plan && (
                            <>
                                {' · '}
                                <Link
                                    href={showPlan(o.plan.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {o.plan.number}
                                </Link>
                            </>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'planned_quantity',
            header: 'Batch',
            sortable: true,
            cell: (o) =>
                `${qty(o.planned_quantity)} ${o.planned_uom?.code ?? ''}${
                    o.planned_units ? ` · ${o.planned_units} units` : ''
                }`,
            cellClassName: 'tabular-nums whitespace-nowrap',
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (o) => (
                <StatusBadge variant={MO_STATUS_VARIANT[o.status]}>
                    {MO_STATUS_LABEL[o.status]}
                </StatusBadge>
            ),
        },
        {
            key: 'started_at',
            header: 'Started',
            sortable: true,
            cell: (o) => date(o.started_at),
        },
        {
            key: 'output',
            header: 'Output',
            cell: (o) =>
                o.output_lot ? (
                    <span>
                        {o.output_lot.batch_number}{' '}
                        <StatusBadge
                            variant={QC_VARIANT[o.output_lot.qc_status]}
                            className="ml-1"
                        >
                            {QC_LABEL[o.output_lot.qc_status]}
                        </StatusBadge>
                    </span>
                ) : o.output_quantity ? (
                    `${qty(o.output_quantity)} ${o.planned_uom?.code ?? ''}`
                ) : (
                    '—'
                ),
        },
        {
            key: 'completed_at',
            header: 'Completed',
            sortable: true,
            cell: (o) => date(o.completed_at),
            defaultHidden: true,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (o) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(o.id)}>Open</Link>
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
            <Head title="Manufacturing orders" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Manufacturing orders"
                    description="Batches being made. Approving an order holds its materials; starting issues the raw materials; completing posts the finished batch."
                />
                <DataTable
                    columns={columns}
                    rows={orders}
                    state={table}
                    baseUrl={index().url}
                    storageKey="manufacturing"
                    getRowKey={(o) => o.id}
                    rowHref={(o) => show(o.id).url}
                    filters={filters}
                    searchPlaceholder="Search order, plan, formula or product…"
                    emptyTitle="Nothing in production"
                    emptyDescription="Open a manufacturing order from a checked production plan."
                />
            </div>
        </>
    );
}

ManufacturingIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Manufacturing', href: index() },
    ],
};
