import { Head } from '@inertiajs/react';
import {
    ItemTable,
    type ItemModuleConfig,
} from '@/components/items/item-table';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/packaging-materials';
import type { Item, Paginated, SelectOption, TableState } from '@/types';

const config: ItemModuleConfig = {
    title: 'Packaging Materials',
    description: 'Bottles, caps, labels and cartons.',
    noun: 'packaging material',
    indexUrl: index().url,
    createUrl: create().url,
    showUrl: (item: Item) => show(item.id).url,
    storageKey: 'packaging-materials',
};

export default function PackagingMaterialIndex({
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
            <Head title="Packaging Materials" />
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

PackagingMaterialIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Packaging Materials', href: index() },
    ],
};
