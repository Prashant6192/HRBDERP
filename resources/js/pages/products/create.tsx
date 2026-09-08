import { Head } from '@inertiajs/react';
import { ItemForm } from '@/components/items/item-form';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/products';
import type { SelectOption, UomOption } from '@/types';

export default function CreateProduct({
    categories,
    uoms,
    itemType,
}: {
    categories: SelectOption[];
    uoms: UomOption[];
    itemType: string;
}) {
    return (
        <>
            <Head title="New product" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New product"
                    description="Finished goods that are sold."
                />

                <div className="max-w-4xl">
                    <ItemForm
                        itemType={itemType}
                        categories={categories}
                        uoms={uoms}
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Create product"
                    />
                </div>
            </div>
        </>
    );
}

CreateProduct.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Products', href: index() },
        { title: 'New', href: create() },
    ],
};
