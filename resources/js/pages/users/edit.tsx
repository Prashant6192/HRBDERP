import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { dashboard } from '@/routes';
import { edit, index, update } from '@/routes/users';
import type { ErpUser, SelectOption } from '@/types';
import { UserForm } from './user-form';

export default function EditUser({
    user,
    userRoles,
    roles,
    departments,
    statuses,
    canAssignRoles,
}: {
    user: ErpUser;
    userRoles: string[];
    roles: SelectOption[];
    departments: SelectOption[];
    statuses: SelectOption[];
    canAssignRoles: boolean;
}) {
    return (
        <>
            <Head title={`Edit ${user.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Edit ${user.name}`}
                    description={user.email}
                />

                <div className="max-w-4xl">
                    <UserForm
                        user={user}
                        userRoles={userRoles}
                        roles={roles}
                        departments={departments}
                        statuses={statuses}
                        canAssignRoles={canAssignRoles}
                        action={{ url: update(user.id).url, method: 'put' }}
                        submitLabel="Save changes"
                    />
                </div>
            </div>
        </>
    );
}

EditUser.layout = ({ user }: { user: ErpUser }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
        { title: user.name, href: edit(user.id) },
    ],
});
