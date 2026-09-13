import { Head } from '@inertiajs/react';
import { ItemDetails, type ItemStock } from '@/components/items/item-details';
import { create as createReceipt } from '@/routes/goods-receipts';
import { PackagingBom } from '@/components/items/packaging-bom';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/products';
import type { Item, ProductPackagingLine, SelectOption } from '@/types';

export default function ShowProduct({
    item,
    can,
    stock,
    packagingLines,
    packagingOptions,
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
    packagingLines: ProductPackagingLine[];
    packagingOptions: SelectOption[];
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
            <div className="px-4 pb-6 sm:px-6">
                <PackagingBom
                    productId={item.id}
                    lines={packagingLines}
                    options={packagingOptions}
                    canEdit={can.update}
                />
            </div>
        </>
    );
}

ShowProduct.layout = ({ item }: { item: Item }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Products', href: index() },
        { title: item.code, href: show(item.id) },
    ],
});
