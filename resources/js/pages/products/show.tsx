import { Head } from '@inertiajs/react';
import { ItemDetails } from '@/components/items/item-details';
import { PackagingBom } from '@/components/items/packaging-bom';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/products';
import type { Item, ProductPackagingLine, SelectOption } from '@/types';

export default function ShowProduct({
    item,
    can,
    packagingLines,
    packagingOptions,
}: {
    item: Item;
    can: { update: boolean; delete: boolean };
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
