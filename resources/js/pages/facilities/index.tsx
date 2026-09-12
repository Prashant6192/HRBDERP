import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import {
    CapabilityBadges,
    StoreBadges,
    money,
} from '@/components/facilities/badges';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/facilities';
import type { FacilityRow, Paginated, SelectOption, TableState } from '@/types';

export default function FacilitiesIndex({
    facilities,
    table,
    types,
    cities,
    capabilities,
    can,
}: {
    facilities: Paginated<FacilityRow>;
    table: TableState;
    types: SelectOption[];
    cities: SelectOption[];
    capabilities: SelectOption[];
    can: { create: boolean };
}) {
    const columns: DataTableColumn<FacilityRow>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            cell: (row) => row.code,
            cellClassName: 'font-mono text-xs font-medium',
        },
        {
            key: 'name',
            header: 'Facility',
            sortable: true,
            cell: (row) => (
                <div>
                    <div className="font-medium">{row.name}</div>
                    <div className="mt-1">
                        <CapabilityBadges capabilities={row.capabilities} />
                    </div>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Facility Type',
            cell: (row) => row.type ?? '—',
        },
        {
            key: 'city',
            header: 'City',
            sortable: true,
            cell: (row) =>
                [row.city, row.state].filter(Boolean).join(', ') || '—',
        },
        {
            key: 'stores',
            header: 'Stores',
            cell: (row) => <StoreBadges stores={row.stores} />,
        },
        {
            key: 'employees_count',
            header: 'Employees',
            cell: (row) => row.employees_count,
            cellClassName: 'tabular-nums',
        },
        {
            key: 'stock_value',
            header: 'Stock Value',
            cell: (row) => money(row.stock_value),
            cellClassName: 'tabular-nums',
        },
        {
            key: 'manager',
            header: 'Manager',
            cell: (row) => row.manager ?? '—',
            defaultHidden: true,
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
            label: 'Facility type',
            options: types.map((t) => ({
                value: String(t.value),
                label: t.label,
            })),
        },
        {
            key: 'city',
            label: 'City',
            options: cities.map((c) => ({
                value: String(c.value),
                label: c.label,
            })),
        },
        {
            key: 'capability',
            label: 'Capability',
            options: capabilities.map((c) => ({
                value: String(c.value),
                label: c.label,
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
            <Head title="Facilities & Warehouses" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Facilities & Warehouses"
                    description="Manage manufacturing facilities, warehouses, stores and inventory locations."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New Facility
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    rows={facilities}
                    state={table}
                    baseUrl={index().url}
                    storageKey="facilities"
                    getRowKey={(row) => row.id}
                    rowHref={(row) => show(row.id).url}
                    filters={filters}
                    searchPlaceholder="Search code, name or city…"
                    emptyTitle="No facilities yet"
                    emptyDescription="Set up your first facility — the plant or a warehouse — and its stores."
                />
            </div>
        </>
    );
}

FacilitiesIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facilities & Warehouses', href: index() },
    ],
};
