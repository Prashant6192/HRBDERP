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
import { PLAN_STATUS_LABEL, PLAN_STATUS_VARIANT } from '@/lib/planning';
import { date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/plans';
import type {
    Paginated,
    ProductionPlan,
    SelectOption,
    TableState,
} from '@/types';

export default function PlanIndex({
    plans,
    table,
    statuses,
    can,
}: {
    plans: Paginated<ProductionPlan>;
    table: TableState;
    statuses: SelectOption[];
    can: { create: boolean };
}) {
    const columns: DataTableColumn<ProductionPlan>[] = [
        {
            key: 'number',
            header: 'Plan',
            sortable: true,
            cell: (p) => p.number,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'formula',
            header: 'Formula / product',
            cell: (p) => (
                <div>
                    <div className="font-medium">{p.formula?.name}</div>
                    <div className="text-muted-foreground text-xs">
                        {p.product?.name ?? 'No product linked'}
                    </div>
                </div>
            ),
        },
        {
            key: 'planned_quantity',
            header: 'Batch',
            sortable: true,
            cell: (p) =>
                `${qty(p.planned_quantity)} ${p.planned_uom?.code ?? ''}${
                    p.planned_units ? ` · ${p.planned_units} units` : ''
                }`,
            cellClassName: 'tabular-nums whitespace-nowrap',
        },
        {
            key: 'shortage',
            header: 'Shortages',
            cell: (p) =>
                p.short_lines_count ? (
                    <StatusBadge variant="destructive">
                        {p.short_lines_count} short
                    </StatusBadge>
                ) : (
                    <StatusBadge variant="success">All available</StatusBadge>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (p) => (
                <StatusBadge variant={PLAN_STATUS_VARIANT[p.status]}>
                    {PLAN_STATUS_LABEL[p.status]}
                </StatusBadge>
            ),
        },
        {
            key: 'planned_start_date',
            header: 'Start',
            sortable: true,
            cell: (p) => date(p.planned_start_date),
        },
        {
            key: 'created_at',
            header: 'Raised',
            sortable: true,
            cell: (p) =>
                `${date(p.created_at)}${p.created_by ? ` · ${p.created_by.name}` : ''}`,
            defaultHidden: true,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (p) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(p.id)}>Open</Link>
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
            <Head title="Production plans" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Production plans"
                    description="Choose a formula and a batch size; the ERP checks the raw material and packaging stores and raises the material requests."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    Plan a batch
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={plans}
                    state={table}
                    baseUrl={index().url}
                    storageKey="plans"
                    getRowKey={(p) => p.id}
                    rowHref={(p) => show(p.id).url}
                    filters={filters}
                    searchPlaceholder="Search plan, formula or product…"
                    emptyTitle="No plans yet"
                    emptyDescription="Plan a batch to see what the stores can give and what must be ordered."
                />
            </div>
        </>
    );
}

PlanIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Production plans', href: index() },
    ],
};
