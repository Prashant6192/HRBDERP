import { Head, Link, router } from '@inertiajs/react';
import { Pencil, ShieldCheck, UserCheck, UserX } from 'lucide-react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { activate, deactivate, edit, index, show } from '@/routes/users';
import { dashboard } from '@/routes';
import type { ErpUser } from '@/types';

const STATUS_VARIANT: Record<
    ErpUser['status'],
    'success' | 'muted' | 'destructive'
> = {
    active: 'success',
    inactive: 'muted',
    suspended: 'destructive',
};

export default function ShowUser({
    user,
    userRoles,
    userPermissions,
    can,
}: {
    user: ErpUser;
    userRoles: string[];
    userPermissions: string[];
    can: {
        update: boolean;
        delete: boolean;
        deactivate: boolean;
        assignRoles: boolean;
    };
}) {
    const isActive = user.status === 'active';

    return (
        <>
            <Head title={user.name} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={user.name}
                    description={user.email}
                    actions={
                        <>
                            {can.update && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(user.id)}>
                                        <Pencil className="size-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}

                            {can.deactivate &&
                                (isActive ? (
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="outline">
                                                <UserX className="size-4" />
                                                Deactivate
                                            </Button>
                                        }
                                        title={`Deactivate ${user.name}?`}
                                        description="They will be signed out on their next request and will not be able to sign back in. Their history is kept."
                                        confirmLabel="Deactivate"
                                        destructive
                                        action={() =>
                                            router.post(deactivate(user.id).url)
                                        }
                                    />
                                ) : (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(activate(user.id).url)
                                        }
                                    >
                                        <UserCheck className="size-4" />
                                        Reactivate
                                    </Button>
                                ))}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <h2 className="mb-5 font-semibold">Employee details</h2>
                        <dl className="grid gap-5 sm:grid-cols-2">
                            <DetailItem label="Employee code">
                                {user.employee_code ?? '—'}
                            </DetailItem>
                            <DetailItem label="Department">
                                {user.department?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Designation">
                                {user.designation ?? '—'}
                            </DetailItem>
                            <DetailItem label="Phone">
                                {user.phone ?? '—'}
                            </DetailItem>
                            <DetailItem label="Last sign-in">
                                {user.last_login_at
                                    ? new Date(
                                          user.last_login_at,
                                      ).toLocaleString()
                                    : 'Never'}
                            </DetailItem>
                            <DetailItem label="Deactivated">
                                {user.deactivated_at
                                    ? new Date(
                                          user.deactivated_at,
                                      ).toLocaleString()
                                    : '—'}
                            </DetailItem>
                        </dl>
                    </section>

                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Access</h2>

                        <div className="space-y-4">
                            <div className="space-y-2">
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    Status
                                </p>
                                <StatusBadge
                                    variant={STATUS_VARIANT[user.status]}
                                >
                                    {user.status}
                                </StatusBadge>
                            </div>

                            <div className="space-y-2">
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    Roles
                                </p>
                                <div className="flex flex-wrap gap-1">
                                    {userRoles.length === 0 ? (
                                        <span className="text-muted-foreground text-sm">
                                            No roles assigned
                                        </span>
                                    ) : (
                                        userRoles.map((role) => (
                                            <StatusBadge
                                                key={role}
                                                variant="info"
                                            >
                                                {role}
                                            </StatusBadge>
                                        ))
                                    )}
                                </div>
                            </div>
                        </div>
                    </section>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="flex items-center gap-2 border-b px-5 py-4">
                        <ShieldCheck className="text-muted-foreground size-4" />
                        <h2 className="font-semibold">Effective permissions</h2>
                        <span className="text-muted-foreground text-sm">
                            ({userPermissions.length})
                        </span>
                    </div>

                    {userPermissions.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            This account holds no permissions and can reach
                            nothing beyond its own profile.
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-1.5 p-5">
                            {userPermissions.map((permission) => (
                                <code
                                    key={permission}
                                    className="bg-muted rounded px-2 py-1 font-mono text-xs"
                                >
                                    {permission}
                                </code>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

ShowUser.layout = ({ user }: { user: ErpUser }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
        { title: user.name, href: show(user.id) },
    ],
});
