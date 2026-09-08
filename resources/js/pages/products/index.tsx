import { Head } from '@inertiajs/react';
import type { DataTableColumn } from '@/components/data-table';
import {
    ItemTable,
    type ItemModuleConfig,
} from '@/components/items/item-table';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/products';
import type { Item, Paginated, SelectOption, TableState } from '@/types';

/**
 * Brand and MRP matter for a finished good and mean nothing for a raw
 * material, so they are added here rather than carried by every item list.
 */
const productColumns: DataTableColumn<Item>[] = [
    {
        key: 'brand',
        header: 'Brand',
        cell: (row) => row.brand ?? '—',
    },
    {
        key: 'mrp',
        header: 'MRP',
        cell: (row) =>
            row.mrp === null
                ? '—'
                : `₹${Number(row.mrp).toLocaleString('en-IN')}`,
        cellClassName: 'tabular-nums',
    },
];

const config: ItemModuleConfig = {
    title: 'Products',
    description: 'Finished goods that are sold.',
    noun: 'product',
    indexUrl: index().url,
    createUrl: create().url,
    showUrl: (item: Item) => show(item.id).url,
    storageKey: 'products',
    extraColumns: productColumns,
};

export default function ProductIndex({
    items,
    table,
    categories,
    can,
}: {
    items: Paginated<Item>;
    table: TableState;
    categories: SelectOption[];
    can: { create: boolean; export: boolean; import: boolean };
}) {
    return (
        <>
            <Head title="Products" />
            <ItemTable
                config={config}
                items={items}
                table={table}
                categories={categories}
                can={can}
            />
        </>
    );
}

ProductIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Products', href: index() },
    ],
};
