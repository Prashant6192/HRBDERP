import { Head } from '@inertiajs/react';
import { ItemForm } from '@/components/items/item-form';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/raw-materials';
import type { SelectOption, UomOption } from '@/types';

export default function CreateRawMaterial({
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
            <Head title="New raw material" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New raw material"
                    description="Materials that go into a formulation."
                />

                <div className="max-w-4xl">
                    <ItemForm
                        itemType={itemType}
                        categories={categories}
                        uoms={uoms}
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Create raw material"
                    />
                </div>
            </div>
        </>
    );
}

CreateRawMaterial.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Raw Materials', href: index() },
        { title: 'New', href: create() },
    ],
};
