import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { edit, index, update } from '@/routes/customers';
import type { Customer, SelectOption } from '@/types';
import { CustomerForm } from './customer-form';

export default function EditCustomer({
    customer,
    kinds,
    clients,
}: {
    customer: Customer;
    kinds: SelectOption[];
    clients: SelectOption[];
}) {
    return (
        <>
            <Head title={`Edit ${customer.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={customer.name}
                    description={`${customer.code} · edit the customer's details.`}
                />

                <div className="max-w-4xl">
                    <CustomerForm
                        customer={customer}
                        kinds={kinds}
                        clients={clients}
                        action={{ url: update(customer.id).url, method: 'put' }}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditCustomer.layout = ({ customer }: { customer: Customer }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Customers', href: index() },
        { title: customer.code, href: edit(customer.id) },
    ],
});
