import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, edit, index } from '@/routes/customers';
import type { CustomerRow, Paginated, SelectOption, TableState } from '@/types';

export default function CustomerIndex({
    customers,
    table,
    kinds,
    can,
}: {
    customers: Paginated<CustomerRow>;
    table: TableState;
    kinds: SelectOption[];
    can: { create: boolean; edit: boolean };
}) {
    const columns: DataTableColumn<CustomerRow>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            cell: (r) => r.code,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'name',
            header: 'Customer',
            sortable: true,
            cell: (r) => (
                <div>
                    <div>{r.name}</div>
                    {r.legal_name && r.legal_name !== r.name && (
                        <div className="text-muted-foreground text-xs">
                            {r.legal_name}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'kind',
            header: 'Kind',
            sortable: true,
            cell: (r) => (
                <div>
                    <StatusBadge variant={r.client ? 'info' : 'muted'}>
                        {r.kind_label}
                    </StatusBadge>
                    {r.client && (
                        <div className="text-muted-foreground mt-1 text-xs">
                            Goods of {r.client}
                        </div>
                    )}
                </div>
            ),
        },
        {
            key: 'gstin',
            header: 'GSTIN',
            cell: (r) => r.gstin ?? 'Unregistered',
            cellClassName: 'font-mono text-xs',
        },
        {
            key: 'billing_city',
            header: 'City',
            sortable: true,
            cell: (r) =>
                [r.billing_city, r.billing_state].filter(Boolean).join(', ') ||
                '—',
        },
        {
            key: 'contact',
            header: 'Contact',
            defaultHidden: true,
            cell: (r) =>
                [r.contact_person, r.phone].filter(Boolean).join(' · ') || '—',
        },
        {
            key: 'dispatches_count',
            header: 'Consignments',
            cell: (r) => r.dispatches_count,
            cellClassName: 'text-right tabular-nums',
            headerClassName: 'text-right',
        },
        {
            key: 'is_active',
            header: 'Status',
            sortable: true,
            cell: (r) => <ActiveBadge active={r.is_active} />,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (r) =>
                can.edit && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={edit(r.id)}>Edit</Link>
                    </Button>
                ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'kind',
            label: 'Kind',
            options: kinds.map((k) => ({
                value: String(k.value),
                label: k.label,
            })),
        },
        {
            key: 'status',
            label: 'Status',
            options: [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
            ],
        },
    ];

    return (
        <>
            <Head title="Customers" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Customers"
                    description="Who finished goods are billed to and sent to: contract clients taking their own goods, marketplaces, distributors."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New customer
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    rows={customers}
                    state={table}
                    baseUrl={index().url}
                    storageKey="customers"
                    getRowKey={(r) => r.id}
                    rowHref={can.edit ? (r) => edit(r.id).url : undefined}
                    filters={filters}
                    searchPlaceholder="Search name, code, GSTIN or city…"
                    emptyTitle="No customers yet"
                    emptyDescription="Add the parties goods are dispatched to. A contract client is added here too, linked to their client record, so their batches can be sent to them."
                />
            </div>
        </>
    );
}

CustomerIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Customers', href: index() },
    ],
};
