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
import { create, index, show } from '@/routes/clients';
import type { Client, Paginated, TableState } from '@/types';

type Row = Client & { open_jobs_count: number; products_count: number };

export default function ClientIndex({
    clients,
    table,
    can,
}: {
    clients: Paginated<Row>;
    table: TableState;
    can: { create: boolean };
}) {
    const columns: DataTableColumn<Row>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            cell: (c) => c.code,
            cellClassName: 'font-mono whitespace-nowrap',
        },
        {
            key: 'name',
            header: 'Client',
            sortable: true,
            cell: (c) => (
                <div>
                    <div className="font-medium">{c.name}</div>
                    <div className="text-muted-foreground text-xs">
                        {c.legal_name ?? ''}
                        {c.gstin ? ` · ${c.gstin}` : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'billing_city',
            header: 'City',
            sortable: true,
            cell: (c) => c.billing_city ?? '—',
        },
        {
            key: 'contact',
            header: 'Contact',
            cell: (c) =>
                c.contact_person
                    ? `${c.contact_person}${c.phone ? ` · ${c.phone}` : ''}`
                    : (c.phone ?? '—'),
        },
        {
            key: 'jobs',
            header: 'Open jobs',
            cell: (c) =>
                c.open_jobs_count > 0 ? (
                    <StatusBadge variant="info">
                        {c.open_jobs_count}
                    </StatusBadge>
                ) : (
                    '—'
                ),
        },
        {
            key: 'products',
            header: 'Products',
            cell: (c) => c.products_count || '—',
        },
        {
            key: 'payment_terms_days',
            header: 'Terms',
            sortable: true,
            cell: (c) =>
                c.payment_terms_days !== null
                    ? `${c.payment_terms_days} days`
                    : '—',
            defaultHidden: true,
        },
        {
            key: 'is_active',
            header: 'Status',
            sortable: true,
            cell: (c) => <ActiveBadge active={c.is_active} />,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (c) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(c.id)}>Open</Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
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
            <Head title="Contract clients" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Contract clients"
                    description="Brands the company manufactures for. A client's batches run through the same planning, stores, QC and manufacturing as our own, tagged with the client throughout."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New client
                                </Link>
                            </Button>
                        )
                    }
                />
                <DataTable
                    columns={columns}
                    rows={clients}
                    state={table}
                    baseUrl={index().url}
                    storageKey="clients"
                    getRowKey={(c) => c.id}
                    rowHref={(c) => show(c.id).url}
                    filters={filters}
                    searchPlaceholder="Search code, name, GSTIN or contact…"
                    emptyTitle="No contract clients yet"
                    emptyDescription="Add the first client to start planning third-party batches."
                />
            </div>
        </>
    );
}

ClientIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Contract clients', href: index() },
    ],
};
