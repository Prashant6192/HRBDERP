import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import type { Item, Paginated, SelectOption, TableState } from '@/types';

export type ItemModuleConfig = {
    title: string;
    description: string;
    /** Singular noun used in buttons and empty states. */
    noun: string;
    indexUrl: string;
    createUrl: string;
    showUrl: (item: Item) => string;
    storageKey: string;
    /** Columns beyond the shared ones, e.g. brand and MRP for products. */
    extraColumns?: DataTableColumn<Item>[];
};

export function ItemTable({
    config,
    items,
    table,
    categories,
    can,
}: {
    config: ItemModuleConfig;
    items: Paginated<Item>;
    table: TableState;
    categories: SelectOption[];
    can: { create: boolean; export: boolean; import: boolean };
}) {
    const columns: DataTableColumn<Item>[] = [
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
            key: 'category',
            header: 'Category',
            cell: (row) => row.category?.name ?? '—',
        },
        {
            key: 'stock_uom',
            header: 'Stock unit',
            cell: (row) => row.stock_uom?.code ?? '—',
        },
        ...(config.extraColumns ?? []),
        {
            key: 'standard_cost',
            header: 'Standard cost',
            sortable: true,
            cell: (row) =>
                row.standard_cost === null
                    ? '—'
                    : `₹${Number(row.standard_cost).toLocaleString('en-IN', {
                          minimumFractionDigits: 2,
                          maximumFractionDigits: 2,
                      })}`,
            cellClassName: 'tabular-nums whitespace-nowrap',
            headerClassName: 'text-right',
        },
        {
            key: 'flags',
            header: 'Controls',
            cell: (row) => (
                <div className="flex flex-wrap gap-1">
                    {row.requires_qc && (
                        <StatusBadge variant="warning">QC</StatusBadge>
                    )}
                    {row.is_batch_tracked && (
                        <StatusBadge variant="info">Batch</StatusBadge>
                    )}
                </div>
            ),
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
                    <Link href={config.showUrl(row)}>Open</Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'category',
            label: 'Categories',
            options: categories.map((category) => ({
                value: String(category.value),
                label: category.label,
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
        <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
            <PageHeader
                title={config.title}
                description={config.description}
                actions={
                    can.create && (
                        <Button asChild>
                            <Link href={config.createUrl}>
                                <Plus className="size-4" />
                                New {config.noun}
                            </Link>
                        </Button>
                    )
                }
            />

            <DataTable
                columns={columns}
                rows={items}
                state={table}
                baseUrl={config.indexUrl}
                storageKey={config.storageKey}
                getRowKey={(row) => row.id}
                rowHref={(row) => config.showUrl(row)}
                filters={filters}
                searchPlaceholder="Search code, name or barcode…"
                emptyTitle={`No ${config.noun}s`}
                emptyDescription={`Add a ${config.noun} to begin recording stock and costs against it.`}
            />
        </div>
    );
}
