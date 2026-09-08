import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { edit, index, update } from '@/routes/vendors';
import type { Vendor } from '@/types';
import { VendorForm } from './vendor-form';

export default function EditVendor({ vendor }: { vendor: Vendor }) {
    return (
        <>
            <Head title={`Edit ${vendor.code}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Edit ${vendor.code}`}
                    description={vendor.name}
                />

                <div className="max-w-4xl">
                    <VendorForm
                        vendor={vendor}
                        action={{ url: update(vendor.id).url, method: 'put' }}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditVendor.layout = ({ vendor }: { vendor: Vendor }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Vendors', href: index() },
        { title: vendor.code, href: edit(vendor.id) },
    ],
});
