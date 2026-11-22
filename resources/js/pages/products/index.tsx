import { Head, Link } from '@inertiajs/react';
import { ScanBarcode } from 'lucide-react';
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
    {
        key: 'barcode',
        header: 'Barcode',
        cell: (row) =>
            row.barcode ? (
                <span className="font-mono text-xs">{row.barcode}</span>
            ) : row.pack_of_item_id ? (
                <span className="text-muted-foreground text-xs">
                    Its single&rsquo;s
                </span>
            ) : (
                <span className="inline-flex items-center gap-1 rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-red-700 dark:text-red-300">
                    <ScanBarcode className="size-3.5" />
                    No barcode
                </span>
            ),
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
    extraFilters: [
        {
            key: 'barcode',
            label: 'Barcode',
            options: [
                { value: 'missing', label: 'No barcode' },
                { value: 'on_file', label: 'Barcode on file' },
            ],
        },
    ],
};

export default function ProductIndex({
    items,
    table,
    categories,
    can,
    no_barcode,
}: {
    items: Paginated<Item>;
    table: TableState;
    categories: SelectOption[];
    can: { create: boolean; export: boolean; import: boolean };
    /** Active products the packing check cannot prove by scan. */
    no_barcode: number;
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
                notice={
                    no_barcode > 0 &&
                    table.filters?.barcode !== 'missing' && (
                        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm">
                            <ScanBarcode className="size-5 shrink-0 text-red-600" />
                            <p className="min-w-0 flex-1">
                                <span className="font-semibold">
                                    {no_barcode}{' '}
                                    {no_barcode === 1
                                        ? 'product has'
                                        : 'products have'}{' '}
                                    no barcode.
                                </span>{' '}
                                The packing screen cannot check them by scan:
                                the packer checks by eye. Open each one and add
                                its barcode, or scan it at stock-in.
                            </p>
                            <Link
                                href={
                                    index({ query: { barcode: 'missing' } }).url
                                }
                                className="font-semibold underline underline-offset-2"
                            >
                                Show them
                            </Link>
                        </div>
                    )
                }
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
