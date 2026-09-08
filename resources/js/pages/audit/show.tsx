import { Head } from '@inertiajs/react';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/audit';
import type { AuditEntry } from '@/types';

/**
 * Audit values are whatever the column held, so they arrive as unknown.
 * Each primitive is narrowed explicitly; anything else is serialised rather
 * than stringified, which would otherwise render as [object Object].
 */
function render(value: unknown): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'string') {
        return value;
    }

    if (
        typeof value === 'number' ||
        typeof value === 'boolean' ||
        typeof value === 'bigint'
    ) {
        return String(value);
    }

    return JSON.stringify(value) ?? '—';
}

export default function ShowAuditEntry({
    entry,
    changes,
}: {
    entry: AuditEntry;
    changes: Record<string, { old: unknown; new: unknown }>;
}) {
    const changeRows = Object.entries(changes);

    return (
        <>
            <Head title={`Audit entry #${entry.id}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={
                        entry.description ?? entry.action.replace(/[._]/g, ' ')
                    }
                    description={new Date(entry.created_at).toLocaleString()}
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <h2 className="mb-5 font-semibold">What happened</h2>
                        <dl className="grid gap-5 sm:grid-cols-2">
                            <DetailItem label="Action">
                                {entry.action}
                            </DetailItem>
                            <DetailItem label="Person">
                                {entry.user_name ?? 'System'}
                                {entry.user_email && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        ({entry.user_email})
                                    </span>
                                )}
                            </DetailItem>
                            <DetailItem label="Record">
                                {entry.auditable_label ?? '—'}
                            </DetailItem>
                            <DetailItem label="Record type">
                                {entry.auditable_type?.split('\\').pop() ?? '—'}
                            </DetailItem>
                            <DetailItem label="Route">
                                {entry.route ?? '—'}
                            </DetailItem>
                            <DetailItem label="IP address">
                                {entry.ip_address ?? '—'}
                            </DetailItem>
                        </dl>
                    </section>

                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Context</h2>
                        {entry.context ? (
                            <dl className="space-y-3">
                                {Object.entries(entry.context).map(
                                    ([key, value]) => (
                                        <DetailItem
                                            key={key}
                                            label={key.replace(/_/g, ' ')}
                                        >
                                            {render(value)}
                                        </DetailItem>
                                    ),
                                )}
                            </dl>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                No additional context recorded.
                            </p>
                        )}

                        {entry.user_agent && (
                            <div className="mt-4 border-t pt-4">
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    User agent
                                </p>
                                <p className="mt-1 font-mono text-xs break-all">
                                    {entry.user_agent}
                                </p>
                            </div>
                        )}
                    </section>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Changes</h2>
                    </div>

                    {changeRows.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            This action did not change any stored values.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>Field</TableHead>
                                    <TableHead>Before</TableHead>
                                    <TableHead>After</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {changeRows.map(([field, change]) => (
                                    <TableRow key={field}>
                                        <TableCell className="font-medium">
                                            {field.replace(/_/g, ' ')}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground font-mono text-xs">
                                            {render(change.old)}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {render(change.new)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

ShowAuditEntry.layout = ({ entry }: { entry: AuditEntry }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Audit log', href: index() },
        { title: `Entry #${entry.id}`, href: show(entry.id) },
    ],
});
