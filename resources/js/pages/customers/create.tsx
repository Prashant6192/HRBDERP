import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/customers';
import type { SelectOption } from '@/types';
import { CustomerForm } from './customer-form';

export default function CreateCustomer({
    nextCode,
    kinds,
    clients,
}: {
    nextCode: string;
    kinds: SelectOption[];
    clients: SelectOption[];
}) {
    return (
        <>
            <Head title="New customer" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New customer"
                    description="Someone finished goods will be billed to and sent to."
                />

                <div className="max-w-4xl">
                    <CustomerForm
                        nextCode={nextCode}
                        kinds={kinds}
                        clients={clients}
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Add customer"
                    />
                </div>
            </div>
        </>
    );
}

CreateCustomer.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Customers', href: index() },
        { title: 'New', href: create() },
    ],
};
