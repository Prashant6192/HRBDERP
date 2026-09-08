import { Head } from '@inertiajs/react';
import { ItemForm } from '@/components/items/item-form';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { edit, index, update } from '@/routes/products';
import type { Item, SelectOption, UomOption } from '@/types';

export default function EditProduct({
    item,
    categories,
    uoms,
    itemType,
}: {
    item: Item;
    categories: SelectOption[];
    uoms: UomOption[];
    itemType: string;
}) {
    return (
        <>
            <Head title={`Edit ${item.code}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Edit ${item.code}`}
                    description={item.name}
                />

                <div className="max-w-4xl">
                    <ItemForm
                        item={item}
                        itemType={itemType}
                        categories={categories}
                        uoms={uoms}
                        action={{ url: update(item.id).url, method: 'put' }}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditProduct.layout = ({ item }: { item: Item }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Products', href: index() },
        { title: item.code, href: edit(item.id) },
    ],
});
