import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { edit, index, update } from '@/routes/warehouses';
import type { SelectOption, Warehouse } from '@/types';
import { WarehouseForm } from './warehouse-form';

export default function EditWarehouse({
    warehouse,
    types,
    managers,
    facilities,
    categories,
}: {
    warehouse: Warehouse;
    types: SelectOption[];
    managers: SelectOption[];
    facilities: SelectOption[];
    categories: (SelectOption & { kind: string })[];
}) {
    return (
        <>
            <Head title={`Edit ${warehouse.code}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Edit ${warehouse.code}`}
                    description={warehouse.name}
                />

                <div className="max-w-4xl">
                    <WarehouseForm
                        warehouse={warehouse}
                        types={types}
                        managers={managers}
                        facilities={facilities}
                        categories={categories}
                        action={{
                            url: update(warehouse.id).url,
                            method: 'put',
                        }}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditWarehouse.layout = ({ warehouse }: { warehouse: Warehouse }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Warehouses', href: index() },
        { title: warehouse.code, href: edit(warehouse.id) },
    ],
});
