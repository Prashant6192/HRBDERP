import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/users';
import type { SelectOption } from '@/types';
import { UserForm } from './user-form';

export default function CreateUser({
    roles,
    departments,
    statuses,
}: {
    roles: SelectOption[];
    departments: SelectOption[];
    statuses: SelectOption[];
}) {
    return (
        <>
            <Head title="New user" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New user"
                    description="Create an employee account and assign the roles it needs."
                />

                <div className="max-w-4xl">
                    <UserForm
                        roles={roles}
                        departments={departments}
                        statuses={statuses}
                        canAssignRoles
                        action={{ url: store().url, method: 'post' }}
                        submitLabel="Create user"
                    />
                </div>
            </div>
        </>
    );
}

CreateUser.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
        { title: 'New', href: create() },
    ],
};
