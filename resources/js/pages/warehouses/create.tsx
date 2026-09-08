import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/warehouses';
import type { SelectOption } from '@/types';
import { WarehouseForm } from './warehouse-form';

export default function CreateWarehouse({
    types,
    managers,
}: {
    types: SelectOption[];
    managers: SelectOption[];
}) {
    return (
        <>
            <Head title="New warehouse" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New warehouse"
                    description="Create a store that stock can be received into."
                />

                <div className="max-w-4xl">
                    <WarehouseForm
                        types={types}
                        managers={managers}
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Create warehouse"
                    />
                </div>
            </div>
        </>
    );
}

CreateWarehouse.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Warehouses', href: index() },
        { title: 'New', href: create() },
    ],
};
