import { Head, Link } from '@inertiajs/react';
import { FileCheck2, Plus, Truck } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { TONE_VARIANT, day, rupees, when } from '@/lib/dispatch';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/dispatches';
import type { DispatchRow, Paginated, SelectOption, TableState } from '@/types';

type Summary = {
    consignments: number;
    taxable: string;
    tax: string;
    total: string;
};

export default function DispatchIndex({
    dispatches,
    table,
    summary,
    statuses,
    customers,
    facilities,
    can,
}: {
    dispatches: Paginated<DispatchRow>;
    table: TableState;
    summary: Summary;
    statuses: SelectOption[];
    customers: { value: string; label: string }[];
    facilities: { value: string; label: string }[];
    can: { create: boolean };
}) {
    const columns: DataTableColumn<DispatchRow>[] = [
        {
            key: 'number',
            header: 'Number',
            sortable: true,
            cell: (r) => r.number,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (r) => (
                <StatusBadge variant={TONE_VARIANT[r.status_tone]}>
                    {r.status_label}
                </StatusBadge>
            ),
        },
        {
            key: 'customer',
            header: 'Billed to',
            cell: (r) => (
                <div>
                    <div>{r.customer?.name ?? '—'}</div>
                    <div className="text-muted-foreground text-xs capitalize">
                        {r.customer?.kind.replace(/_/g, ' ')}
                    </div>
                </div>
            ),
        },
        {
            key: 'invoice_number',
            header: 'Invoice',
            sortable: true,
            cell: (r) => (
                <div>
                    <div className="font-mono text-xs">
                        {r.invoice_number ?? '—'}
                    </div>
                    <div className="text-muted-foreground text-xs">
                        {r.invoice_date ? day(r.invoice_date) : ''}
                        {r.has_irn && (
                            <span className="ml-1 inline-flex items-center gap-1 text-emerald-700 dark:text-emerald-300">
                                <FileCheck2 className="size-3" /> IRN
                            </span>
                        )}
                    </div>
                </div>
            ),
        },
        {
            key: 'from',
            header: 'From',
            cell: (r) => (
                <div>
                    <div>{r.facility ?? '—'}</div>
                    <div className="text-muted-foreground font-mono text-xs">
                        {r.store}
                    </div>
                </div>
            ),
        },
        {
            key: 'lines_count',
            header: 'Lines',
            cell: (r) => r.lines_count,
            cellClassName: 'text-right tabular-nums',
            headerClassName: 'text-right',
        },
        {
            key: 'total_value',
            header: 'Invoice value',
            sortable: true,
            cell: (r) => rupees(r.total_value),
            cellClassName: 'text-right tabular-nums whitespace-nowrap',
            headerClassName: 'text-right',
        },
        {
            key: 'transport',
            header: 'Transport',
            defaultHidden: true,
            cell: (r) =>
                r.vehicle_number || r.eway_bill_number ? (
                    <div className="text-xs">
                        <div>{r.vehicle_number ?? '—'}</div>
                        <div className="text-muted-foreground font-mono">
                            {r.eway_bill_number
                                ? `EWB ${r.eway_bill_number}`
                                : ''}
                        </div>
                    </div>
                ) : (
                    '—'
                ),
        },
        {
            key: 'dispatched_at',
            header: 'Left',
            sortable: true,
            cell: (r) => when(r.dispatched_at),
            cellClassName: 'whitespace-nowrap text-muted-foreground text-xs',
        },
        {
            key: 'created_at',
            header: 'Raised',
            sortable: true,
            defaultHidden: true,
            cell: (r) => (
                <div className="text-xs">
                    <div>{when(r.created_at)}</div>
                    <div className="text-muted-foreground">{r.created_by}</div>
                </div>
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
        { key: 'customer', label: 'Customer', options: customers },
        { key: 'facility', label: 'Facility', options: facilities },
        {
            key: 'period',
            label: 'Period',
            options: [
                { value: 'today', label: 'Today' },
                { value: 'week', label: 'Last 7 days' },
                { value: 'month', label: 'This month' },
                { value: 'quarter', label: 'Last 90 days' },
                { value: 'year', label: 'This year' },
            ],
        },
    ];

    return (
        <>
            <Head title="Dispatches" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Dispatches"
                    description="Everything that has left the factory, to whom, on which invoice, on which vehicle — with the invoice and e-invoice kept against it."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New dispatch
                                </Link>
                            </Button>
                        )
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="bg-card rounded-xl border p-5">
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground text-sm">
                                Consignments
                            </span>
                            <Truck className="text-muted-foreground size-4" />
                        </div>
                        <p className="mt-2 text-3xl font-semibold tabular-nums">
                            {summary.consignments}
                        </p>
                        <p className="text-muted-foreground mt-1 text-xs">
                            In the current view, cancelled ones excluded.
                        </p>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <span className="text-muted-foreground text-sm">
                            Taxable value
                        </span>
                        <p className="mt-2 text-2xl font-semibold tabular-nums">
                            {rupees(summary.taxable)}
                        </p>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <span className="text-muted-foreground text-sm">
                            GST
                        </span>
                        <p className="mt-2 text-2xl font-semibold tabular-nums">
                            {rupees(summary.tax)}
                        </p>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <span className="text-muted-foreground text-sm">
                            Invoice value
                        </span>
                        <p className="mt-2 text-2xl font-semibold tabular-nums">
                            {rupees(summary.total)}
                        </p>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    rows={dispatches}
                    state={table}
                    baseUrl={index().url}
                    storageKey="dispatches"
                    getRowKey={(r) => r.id}
                    rowHref={(r) => show(r.id).url}
                    filters={filters}
                    searchPlaceholder="Search number, invoice, IRN, vehicle, e-way bill or customer…"
                    emptyTitle="Nothing has been dispatched yet"
                    emptyDescription="Write up the first consignment from a finished goods store."
                />
            </div>
        </>
    );
}

DispatchIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Dispatches', href: index() },
    ],
};
