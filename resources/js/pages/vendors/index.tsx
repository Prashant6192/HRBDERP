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
import { create, index, show } from '@/routes/vendors';
import type { Paginated, TableState, Vendor } from '@/types';

export default function VendorIndex({
    vendors,
    table,
    can,
}: {
    vendors: Paginated<Vendor>;
    table: TableState;
    can: { create: boolean; export: boolean };
}) {
    const columns: DataTableColumn<Vendor>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            cell: (row) => row.code,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'name',
            header: 'Name',
            sortable: true,
            cell: (row) => row.name,
        },
        {
            key: 'gstin',
            header: 'GSTIN',
            cell: (row) => row.gstin ?? '—',
            cellClassName: 'font-mono text-xs',
            defaultHidden: true,
        },
        {
            key: 'city',
            header: 'City',
            sortable: true,
            cell: (row) => row.city ?? '—',
        },
        {
            key: 'supply_type',
            header: 'Supplies',
            cell: (row) => (
                <span className="capitalize">
                    {row.supply_type.replace(/_/g, ' ')}
                </span>
            ),
        },
        {
            key: 'payment_terms_days',
            header: 'Terms',
            sortable: true,
            cell: (row) =>
                row.payment_terms_days ? `${row.payment_terms_days} days` : '—',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <div className="flex flex-wrap gap-1">
                    <ActiveBadge active={row.is_active} />
                    {row.is_approved ? (
                        <StatusBadge variant="success">Approved</StatusBadge>
                    ) : (
                        <StatusBadge variant="warning">Unapproved</StatusBadge>
                    )}
                </div>
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>Open</Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'supply_type',
            label: 'Supplies',
            options: [
                { value: 'raw_material', label: 'Raw materials' },
                { value: 'packaging', label: 'Packaging' },
                { value: 'services', label: 'Services' },
                { value: 'mixed', label: 'Mixed' },
            ],
        },
        {
            key: 'status',
            label: 'Status',
            options: [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
                { value: 'approved', label: 'Approved' },
                { value: 'unapproved', label: 'Unapproved' },
            ],
        },
    ];

    return (
        <>
            <Head title="Vendors" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Vendors"
                    description="Suppliers of raw materials, packaging and services."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New vendor
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    rows={vendors}
                    state={table}
                    baseUrl={index().url}
                    storageKey="vendors"
                    getRowKey={(row) => row.id}
                    rowHref={(row) => show(row.id).url}
                    filters={filters}
                    searchPlaceholder="Search name, code, GSTIN or city…"
                    emptyTitle="No vendors"
                    emptyDescription="Add a vendor to raise purchase orders against them."
                />
            </div>
        </>
    );
}

VendorIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Vendors', href: index() },
    ],
};
