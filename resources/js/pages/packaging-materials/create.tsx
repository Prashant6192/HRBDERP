import { Head } from '@inertiajs/react';
import { ItemForm } from '@/components/items/item-form';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/packaging-materials';
import type { SelectOption, UomOption } from '@/types';

export default function CreatePackagingMaterial({
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
            <Head title="New packaging material" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New packaging material"
                    description="Bottles, caps, labels and cartons."
                />

                <div className="max-w-4xl">
                    <ItemForm
                        itemType={itemType}
                        categories={categories}
                        uoms={uoms}
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Create packaging material"
                    />
                </div>
            </div>
        </>
    );
}

CreatePackagingMaterial.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Packaging Materials', href: index() },
        { title: 'New', href: create() },
    ],
};
