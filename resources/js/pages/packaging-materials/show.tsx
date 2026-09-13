import { Head } from '@inertiajs/react';
import { ItemDetails, type ItemStock } from '@/components/items/item-details';
import { create as createReceipt } from '@/routes/goods-receipts';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/packaging-materials';
import type { Item } from '@/types';

export default function ShowPackagingMaterial({
    item,
    can,
    stock,
}: {
    item: Item;
    can: {
        update: boolean;
        delete: boolean;
        receive: boolean;
        view_stock: boolean;
        view_qc: boolean;
    };
    stock: ItemStock | null;
}) {
    return (
        <>
            <Head title={item.code} />
            <ItemDetails
                item={item}
                can={can}
                editUrl={edit(item.id).url}
                deleteUrl={destroy(item.id).url}
                receiveUrl={createReceipt({ query: { item: item.id } }).url}
                stock={stock}
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
