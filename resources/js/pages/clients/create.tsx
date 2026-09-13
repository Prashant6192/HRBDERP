import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/clients';
import { ClientForm } from './client-form';

export default function CreateClient({ nextCode }: { nextCode: string }) {
    return (
        <>
            <Head title="New client" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New contract client"
                    description="A brand the company manufactures for. Their products, formulas, material and finished goods are tagged with them all the way through."
                />
                <div className="max-w-4xl">
                    <ClientForm
                        nextCode={nextCode}
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Add client"
                    />
                </div>
            </div>
        </>
    );
}

CreateClient.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Contract clients', href: index() },
        { title: 'New', href: create() },
    ],
};
