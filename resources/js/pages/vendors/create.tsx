import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/vendors';
import { VendorForm } from './vendor-form';

export default function CreateVendor() {
    return (
        <>
            <Head title="New vendor" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New vendor"
                    description="Register a supplier so purchase orders can be raised against them."
                />

                <div className="max-w-4xl">
                    <VendorForm
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Create vendor"
                    />
                </div>
            </div>
        </>
    );
}

CreateVendor.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Vendors', href: index() },
        { title: 'New', href: create() },
    ],
};
