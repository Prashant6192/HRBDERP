import { Head, Link } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/audit';
import type { AuditEntry, Paginated, TableState } from '@/types';

type ActionOption = { value: string; label: string; sensitive: boolean };

export default function AuditIndex({
    entries,
    table,
    actions,
}: {
    entries: Paginated<AuditEntry>;
    table: TableState;
    actions: ActionOption[];
    can: { export: boolean };
}) {
    const sensitive = new Set(
        actions.filter((a) => a.sensitive).map((a) => a.value),
    );

    const labels = new Map(actions.map((a) => [a.value, a.label]));

    const columns: DataTableColumn<AuditEntry>[] = [
        {
            key: 'created_at',
            header: 'When',
            sortable: true,
            cell: (row) => (
                <time dateTime={row.created_at} className="whitespace-nowrap">
                    {new Date(row.created_at).toLocaleString()}
                </time>
            ),
        },
        {
            key: 'user_name',
            header: 'Who',
            sortable: true,
            cell: (row) => row.user_name ?? 'System',
        },
        {
            key: 'action',
            header: 'Action',
            sortable: true,
            cell: (row) => (
                <span className="inline-flex items-center gap-1.5">
                    {sensitive.has(row.action) && (
                        <ShieldAlert className="size-3.5 text-amber-600 dark:text-amber-400" />
                    )}
                    <StatusBadge
                        variant={
                            sensitive.has(row.action) ? 'warning' : 'muted'
                        }
                    >
                        {labels.get(row.action) ?? row.action}
                    </StatusBadge>
                </span>
            ),
        },
        {
            key: 'entity',
            header: 'Record',
            cell: (row) =>
                row.auditable_label ??
                (row.auditable_type
                    ? row.auditable_type.split('\\').pop()
                    : '—'),
        },
        {
            key: 'ip_address',
            header: 'IP',
            cell: (row) => row.ip_address ?? '—',
            cellClassName: 'font-mono text-xs',
            defaultHidden: true,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (row) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(row.id)}>View</Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'action',
            label: 'Actions',
            options: actions.map((a) => ({ value: a.value, label: a.label })),
        },
    ];

    return (
        <>
            <Head title="Audit log" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Audit log"
                    description="Every consequential action, in the order it happened. Entries cannot be edited or deleted by anyone."
                />

                <DataTable
                    columns={columns}
                    rows={entries}
                    state={table}
                    baseUrl={index().url}
                    storageKey="audit"
                    getRowKey={(row) => row.id}
                    rowHref={(row) => show(row.id).url}
                    filters={filters}
                    searchPlaceholder="Search person, record or description…"
                    emptyTitle="Nothing recorded"
                    emptyDescription="No audit entries match the current filters."
                />
            </div>
        </>
    );
}

AuditIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Audit log', href: index() },
    ],
};
