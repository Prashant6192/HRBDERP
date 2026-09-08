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
import { create, index, show } from '@/routes/warehouses';
import type { Paginated, SelectOption, TableState, Warehouse } from '@/types';

export default function WarehouseIndex({
    warehouses,
    table,
    types,
    can,
}: {
    warehouses: Paginated<Warehouse>;
    table: TableState;
    types: SelectOption[];
    can: { create: boolean; export: boolean };
}) {
    const columns: DataTableColumn<Warehouse>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            cell: (row) => row.code,
            cellClassName: 'font-medium',
        },
        {
            key: 'name',
            header: 'Name',
            sortable: true,
            cell: (row) => row.name,
        },
        {
            key: 'type',
            header: 'Type',
            sortable: true,
            cell: (row) => (
                <StatusBadge variant={row.is_quarantine ? 'warning' : 'info'}>
                    {types.find((t) => t.value === row.type)?.label ?? row.type}
                </StatusBadge>
            ),
        },
        {
            key: 'city',
            header: 'City',
            sortable: true,
            cell: (row) => row.city ?? '—',
        },
        {
            key: 'manager',
            header: 'Manager',
            cell: (row) => row.manager?.name ?? '—',
            defaultHidden: true,
        },
        {
            key: 'locations_count',
            header: 'Locations',
            cell: (row) => row.locations_count ?? 0,
            cellClassName: 'tabular-nums',
        },
        {
            key: 'is_active',
            header: 'Status',
            sortable: true,
            cell: (row) => <ActiveBadge active={row.is_active} />,
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
            key: 'type',
            label: 'Types',
            options: types.map((t) => ({
                value: String(t.value),
                label: t.label,
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
            <Head title="Warehouses" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Warehouses"
                    description="Physical stores and the locations inside them."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New warehouse
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    rows={warehouses}
                    state={table}
                    baseUrl={index().url}
                    storageKey="warehouses"
                    getRowKey={(row) => row.id}
                    rowHref={(row) => show(row.id).url}
                    filters={filters}
                    searchPlaceholder="Search code, name or city…"
                    emptyTitle="No warehouses"
                    emptyDescription="Add a warehouse to start recording stock against it."
                />
            </div>
        </>
    );
}

WarehouseIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Warehouses', href: index() },
    ],
};
