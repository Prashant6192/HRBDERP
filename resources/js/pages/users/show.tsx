import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Building2,
    Pencil,
    ShieldCheck,
    Star,
    UserCheck,
    UserPlus,
    UserX,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem, Field } from '@/components/form-field';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import employeeAssignments from '@/routes/employee-assignments';
import { show as showFacility } from '@/routes/facilities';
import facilityEmployees from '@/routes/facilities/employees';
import type { EmployeeAssignmentRow } from '@/types';
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

type FacilityOption = {
    value: number;
    label: string;
    stores: { value: number; label: string }[];
};

function AssignmentsSection({
    user,
    assignments,
    companyWide,
    facilities,
    canAssign,
}: {
    user: ErpUser;
    assignments: EmployeeAssignmentRow[];
    companyWide: boolean;
    facilities: FacilityOption[];
    canAssign: boolean;
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        user_id: String(user.id),
        facility_id: '',
        store_id: '',
        is_primary: false,
        designation: '',
        effective_from: '',
    });
    const facility = facilities.find(
        (f) => String(f.value) === form.data.facility_id,
    );

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div className="flex items-center gap-2">
                    <Building2 className="text-muted-foreground size-4" />
                    <h2 className="font-semibold">Facility assignments</h2>
                    <span className="text-muted-foreground text-sm">
                        ({assignments.length})
                    </span>
                </div>
                {canAssign && (
                    <Button
                        variant={adding ? 'ghost' : 'outline'}
                        size="sm"
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? (
                            <X className="size-4" />
                        ) : (
                            <UserPlus className="size-4" />
                        )}
                        {adding ? 'Cancel' : 'Assign'}
                    </Button>
                )}
            </div>

            {adding && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!form.data.facility_id) return;
                        form.post(
                            facilityEmployees.store(
                                Number(form.data.facility_id),
                            ).url,
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    setAdding(false);
                                },
                            },
                        );
                    }}
                    className="grid gap-3 border-b p-5 sm:grid-cols-2 lg:grid-cols-5"
                >
                    <Field
                        label="Facility"
                        htmlFor="asg-facility"
                        required
                        error={form.errors.facility_id}
                    >
                        <Select
                            value={form.data.facility_id}
                            onValueChange={(v) =>
                                form.setData({
                                    ...form.data,
                                    facility_id: v,
                                    store_id: '',
                                })
                            }
                        >
                            <SelectTrigger id="asg-facility" className="w-full">
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {facilities.map((f) => (
                                    <SelectItem
                                        key={f.value}
                                        value={String(f.value)}
                                    >
                                        {f.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Scope"
                        htmlFor="asg-store"
                        error={form.errors.store_id}
                    >
                        <Select
                            value={form.data.store_id || 'all'}
                            onValueChange={(v) =>
                                form.setData('store_id', v === 'all' ? '' : v)
                            }
                        >
                            <SelectTrigger id="asg-store" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Whole facility
                                </SelectItem>
                                {(facility?.stores ?? []).map((s) => (
                                    <SelectItem
                                        key={s.value}
                                        value={String(s.value)}
                                    >
                                        {s.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Designation"
                        htmlFor="asg-designation"
                        error={form.errors.designation}
                    >
                        <Input
                            id="asg-designation"
                            value={form.data.designation}
                            onChange={(e) =>
                                form.setData('designation', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="From"
                        htmlFor="asg-from"
                        error={form.errors.effective_from}
                    >
                        <Input
                            id="asg-from"
                            type="date"
                            value={form.data.effective_from}
                            onChange={(e) =>
                                form.setData('effective_from', e.target.value)
                            }
                        />
                    </Field>
                    <div className="flex items-end gap-3">
                        <label className="flex items-center gap-2 pb-2 text-sm">
                            <Checkbox
                                checked={form.data.is_primary}
                                onCheckedChange={(c) =>
                                    form.setData('is_primary', c === true)
                                }
                            />
                            Primary
                        </label>
                        <Button
                            type="submit"
                            size="sm"
                            disabled={form.processing || !form.data.facility_id}
                        >
                            Save
                        </Button>
                    </div>
                </form>
            )}

            {assignments.length === 0 ? (
                <p className="text-muted-foreground p-5 text-sm">
                    {companyWide
                        ? 'No facility assignment: this account acts company-wide.'
                        : 'No facility assignment yet.'}
                </p>
            ) : (
                <ul className="divide-y">
                    {assignments.map((a) => (
                        <li
                            key={a.id}
                            className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                        >
                            <span>
                                <Link
                                    href={showFacility(a.facility_id ?? 0)}
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {a.facility}
                                </Link>
                                {a.store ? (
                                    <StatusBadge
                                        variant="info"
                                        className="ml-2"
                                    >
                                        {a.store}
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge
                                        variant="muted"
                                        className="ml-2"
                                    >
                                        Whole facility
                                    </StatusBadge>
                                )}
                                {a.is_primary && (
                                    <StatusBadge
                                        variant="success"
                                        className="ml-1"
                                    >
                                        <Star className="mr-1 size-3" />
                                        Primary
                                    </StatusBadge>
                                )}
                                {a.designation && (
                                    <span className="text-muted-foreground ml-2 text-xs">
                                        {a.designation}
                                    </span>
                                )}
                            </span>
                            {canAssign && (
                                <span className="flex gap-1">
                                    {!a.is_primary && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    employeeAssignments.primary(
                                                        a.id,
                                                    ).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Make primary
                                        </Button>
                                    )}
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                End
                                            </Button>
                                        }
                                        title="End this assignment?"
                                        description={`${user.name} will no longer act at ${a.facility}${a.store ? ` / ${a.store}` : ''}.`}
                                        confirmLabel="End assignment"
                                        destructive
                                        action={() =>
                                            router.delete(
                                                employeeAssignments.destroy(
                                                    a.id,
                                                ).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export default function ShowUser({
    user,
    userRoles,
    userPermissions,
    assignments,
    companyWide,
    facilities,
    can,
}: {
    user: ErpUser;
    userRoles: string[];
    userPermissions: string[];
    assignments: EmployeeAssignmentRow[];
    companyWide: boolean;
    facilities: FacilityOption[];
    can: {
        update: boolean;
        delete: boolean;
        deactivate: boolean;
        assignRoles: boolean;
        assign: boolean;
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

                <AssignmentsSection
                    user={user}
                    assignments={assignments}
                    companyWide={companyWide}
                    facilities={facilities}
                    canAssign={can.assign}
                />

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
