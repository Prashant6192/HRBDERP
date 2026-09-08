import { Head, Link } from '@inertiajs/react';
import { Pencil, Users } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { edit, index } from '@/routes/roles';

type RoleRow = {
    id: number;
    name: string;
    description: string | null;
    users_count: number;
    permissions_count: number;
    is_built_in: boolean;
};

export default function RoleIndex({
    roles,
    can,
}: {
    roles: RoleRow[];
    can: { update: boolean };
}) {
    return (
        <>
            <Head title="Roles" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Roles & permissions"
                    description="What each role can do. Every permission here is enforced on the server, not by hiding buttons."
                />

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {roles.map((role) => (
                        <article
                            key={role.id}
                            className="bg-card flex flex-col rounded-xl border p-5"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="font-semibold">{role.name}</h2>
                                {role.is_built_in && (
                                    <StatusBadge variant="muted">
                                        Built-in
                                    </StatusBadge>
                                )}
                            </div>

                            {role.description && (
                                <p className="text-muted-foreground mt-2 flex-1 text-sm">
                                    {role.description}
                                </p>
                            )}

                            <dl className="text-muted-foreground mt-4 flex items-center gap-4 text-sm">
                                <div className="flex items-center gap-1.5">
                                    <Users className="size-3.5" />
                                    <span className="tabular-nums">
                                        {role.users_count}
                                    </span>
                                    <span>
                                        {role.users_count === 1
                                            ? 'user'
                                            : 'users'}
                                    </span>
                                </div>
                                <div>
                                    <span className="tabular-nums">
                                        {role.permissions_count}
                                    </span>{' '}
                                    permissions
                                </div>
                            </dl>

                            {can.update && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-4 w-full"
                                    asChild
                                >
                                    <Link href={edit(role.id)}>
                                        <Pencil className="size-3.5" />
                                        Edit permissions
                                    </Link>
                                </Button>
                            )}
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}

RoleIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Roles', href: index() },
    ],
};
