import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/users';
import type { ErpUser, Paginated, SelectOption, TableState } from '@/types';

const STATUS_VARIANT: Record<
    ErpUser['status'],
    'success' | 'muted' | 'destructive'
> = {
    active: 'success',
    inactive: 'muted',
    suspended: 'destructive',
};

export default function UserIndex({
    users,
    table,
    roles,
    departments,
    statuses,
    can,
}: {
    users: Paginated<ErpUser>;
    table: TableState;
    roles: SelectOption[];
    departments: SelectOption[];
    statuses: SelectOption[];
    can: { create: boolean; export: boolean };
}) {
    const columns: DataTableColumn<ErpUser>[] = [
        {
            key: 'employee_code',
            header: 'Employee',
            sortable: true,
            cell: (row) => row.employee_code ?? '—',
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'name',
            header: 'Name',
            sortable: true,
            cell: (row) => row.name,
        },
        {
            key: 'email',
            header: 'Email',
            sortable: true,
            cell: (row) => row.email,
        },
        {
            key: 'department',
            header: 'Department',
            cell: (row) => row.department?.name ?? '—',
        },
        {
            key: 'roles',
            header: 'Roles',
            cell: (row) => (
                <div className="flex flex-wrap gap-1">
                    {(row.roles ?? []).map((role) => (
                        <StatusBadge key={role.id} variant="info">
                            {role.name}
                        </StatusBadge>
                    ))}
                    {(row.roles ?? []).length === 0 && (
                        <span className="text-muted-foreground">None</span>
                    )}
                </div>
            ),
        },
        {
            key: 'last_login_at',
            header: 'Last sign-in',
            sortable: true,
            cell: (row) =>
                row.last_login_at
                    ? new Date(row.last_login_at).toLocaleString()
                    : 'Never',
            defaultHidden: true,
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (row) => (
                <StatusBadge variant={STATUS_VARIANT[row.status]}>
                    {row.status}
                </StatusBadge>
            ),
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>Open</Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'role',
            label: 'Roles',
            options: roles.map((r) => ({
                value: String(r.value),
                label: r.label,
            })),
        },
        {
            key: 'department',
            label: 'Departments',
            options: departments.map((d) => ({
                value: String(d.value),
                label: d.label,
            })),
        },
        {
            key: 'status',
            label: 'Status',
            options: statuses.map((s) => ({
                value: String(s.value),
                label: s.label,
            })),
        },
    ];

    return (
        <>
            <Head title="Users" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Users"
                    description="Employee accounts and the roles they hold."
                    actions={
                        can.create && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus className="size-4" />
                                    New user
                                </Link>
                            </Button>
                        )
                    }
                />

                <DataTable
                    columns={columns}
                    rows={users}
                    state={table}
                    baseUrl={index().url}
                    storageKey="users"
                    getRowKey={(row) => row.id}
                    rowHref={(row) => show(row.id).url}
                    filters={filters}
                    searchPlaceholder="Search name, email or employee code…"
                    emptyTitle="No users"
                    emptyDescription="Add an employee account to give someone access."
                />
            </div>
        </>
    );
}

UserIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Users', href: index() },
    ],
};
