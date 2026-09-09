import { Head, Link } from '@inertiajs/react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    PMR_STATUS_LABEL,
    PMR_STATUS_VARIANT,
    STORE_SHORT,
} from '@/lib/planning';
import { date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/material-requests';
import { show as showPlan } from '@/routes/plans';
import type {
    MaterialRequest,
    Paginated,
    SelectOption,
    TableState,
} from '@/types';

export default function MaterialRequestIndex({
    requests,
    table,
    statuses,
    stores,
}: {
    requests: Paginated<MaterialRequest>;
    table: TableState;
    statuses: SelectOption[];
    stores: SelectOption[];
}) {
    const columns: DataTableColumn<MaterialRequest>[] = [
        {
            key: 'number',
            header: 'PMR',
            sortable: true,
            cell: (r) => r.number,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'store',
            header: 'Store',
            cell: (r) => (
                <span>
                    <StatusBadge variant="muted">
                        {STORE_SHORT[r.store_kind]}
                    </StatusBadge>
                    <span className="text-muted-foreground ml-2 text-xs">
                        {r.warehouse?.code}
                    </span>
                </span>
            ),
        },
        {
            key: 'plan',
            header: 'Plan',
            cell: (r) => (
                <div>
                    {r.plan && (
                        <Link
                            href={showPlan(r.plan.id)}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {r.plan.number}
                        </Link>
                    )}
                    <div className="text-muted-foreground text-xs">
                        {r.plan?.formula?.name}
                        {r.plan
                            ? ` · ${qty(r.plan.planned_quantity)} ${r.plan.planned_uom?.code ?? ''}`
                            : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'lines',
            header: 'Lines',
            cell: (r) => (
                <span className="tabular-nums">
                    {r.lines_count ?? 0}
                    {r.short_lines_count ? (
                        <span className="ml-2 text-red-700 dark:text-red-300">
                            {r.short_lines_count} to order
                        </span>
                    ) : null}
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (r) => (
                <StatusBadge variant={PMR_STATUS_VARIANT[r.status]}>
                    {PMR_STATUS_LABEL[r.status]}
                </StatusBadge>
            ),
        },
        {
            key: 'needed_by',
            header: 'Needed by',
            sortable: true,
            cell: (r) => date(r.needed_by),
        },
        {
            key: 'requested_at',
            header: 'Raised',
            sortable: true,
            cell: (r) => date(r.requested_at),
            defaultHidden: true,
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
            key: 'store',
            label: 'Store',
            options: stores.map((s) => ({
                value: String(s.value),
                label: s.label,
            })),
        },
    ];

    return (
        <>
            <Head title="Material requests" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Material requests"
                    description="Production Material Requests raised from plans: what each store must provide and what purchase must order."
                />
                <DataTable
                    columns={columns}
                    rows={requests}
                    state={table}
                    baseUrl={index().url}
                    storageKey="material-requests"
                    getRowKey={(r) => r.id}
                    rowHref={(r) => show(r.id).url}
                    filters={filters}
                    searchPlaceholder="Search PMR, plan or formula…"
                    emptyTitle="No open material requests"
                    emptyDescription="Requests appear here when a production plan raises them."
                />
            </div>
        </>
    );
}

MaterialRequestIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Material requests', href: index() },
    ],
};
