import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { edit, index, show, update } from '@/routes/clients';
import type { Client } from '@/types';
import { ClientForm } from './client-form';

export default function EditClient({ client }: { client: Client }) {
    return (
        <>
            <Head title={`Edit ${client.code}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader title={client.name} description={client.code} />
                <div className="max-w-4xl">
                    <ClientForm
                        client={client}
                        action={{ url: update(client.id).url, method: 'put' }}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditClient.layout = ({ client }: { client: Client }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Contract clients', href: index() },
        { title: client.code, href: show(client.id) },
        { title: 'Edit', href: edit(client.id) },
    ],
});
