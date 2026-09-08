import { Head } from '@inertiajs/react';
import { ItemDetails } from '@/components/items/item-details';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/packaging-materials';
import type { Item } from '@/types';

export default function ShowPackagingMaterial({
    item,
    can,
}: {
    item: Item;
    can: { update: boolean; delete: boolean };
}) {
    return (
        <>
            <Head title={item.code} />
            <ItemDetails
                item={item}
                can={can}
                editUrl={edit(item.id).url}
                deleteUrl={destroy(item.id).url}
            />
        </>
    );
}

ShowPackagingMaterial.layout = ({ item }: { item: Item }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Packaging Materials', href: index() },
        { title: item.code, href: show(item.id) },
    ],
});
