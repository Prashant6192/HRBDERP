import { Head } from '@inertiajs/react';
import {
    ItemTable,
    type ItemModuleConfig,
} from '@/components/items/item-table';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/raw-materials';
import type { Item, Paginated, SelectOption, TableState } from '@/types';

const config: ItemModuleConfig = {
    title: 'Raw Materials',
    description: 'Materials that go into a formulation.',
    noun: 'raw material',
    indexUrl: index().url,
    createUrl: create().url,
    showUrl: (item: Item) => show(item.id).url,
    storageKey: 'raw-materials',
};

export default function RawMaterialIndex({
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
            <Head title="Raw Materials" />
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

RawMaterialIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Raw Materials', href: index() },
    ],
};
