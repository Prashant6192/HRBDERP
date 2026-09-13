import { Head, router, useForm } from '@inertiajs/react';
import { Download, FileCheck2, FilePlus2, FileX2 } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { approve, index, store, withdraw } from '@/routes/documents';
import type { SelectOption } from '@/types';

type Version = {
    id: number;
    version: number;
    title: string;
    status: 'draft' | 'approved' | 'superseded' | 'withdrawn';
    status_label: string;
    change_summary: string | null;
    effective_from: string | null;
    created_by: string | null;
    created_at: string | null;
    approved_by: string | null;
    approved_at: string | null;
    file_name: string | null;
    download: string | null;
    can_approve: boolean;
    is_author: boolean;
    can_withdraw: boolean;
};

type Group = {
    code: string;
    title: string;
    kind: string;
    kind_label: string;
    item: string | null;
    client: string | null;
    current_version: number | null;
    versions: Version[];
};

const STATUS = {
    draft: 'warning',
    approved: 'success',
    superseded: 'muted',
    withdrawn: 'destructive',
} as const;

export default function DocumentsIndex({
    groups,
    kinds,
    filters,
    items,
    clients,
    can,
}: {
    groups: Group[];
    kinds: SelectOption[];
    filters: { kind: string | null; search: string };
    items: SelectOption[];
    clients: SelectOption[];
    can: { create: boolean };
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        code: string;
        kind: string;
        title: string;
        item_id: string;
        client_id: string;
        change_summary: string;
        effective_from: string;
        file: File | null;
    }>({
        code: '',
        kind: 'sop',
        title: '',
        item_id: '',
        client_id: '',
        change_summary: '',
        effective_from: '',
        file: null,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    const go = (next: Partial<typeof filters>) =>
        router.get(
            index().url,
            {
                ...((next.kind ?? filters.kind)
                    ? { kind: next.kind ?? filters.kind }
                    : {}),
                ...((next.search ?? filters.search)
                    ? { search: next.search ?? filters.search }
                    : {}),
            },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <>
            <Head title="Controlled documents" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Controlled documents"
                    description="SOPs, specifications, artworks, certificates of analysis, formula documents and QC standards, with version history and approval status. Production references the approved current version only."
                    actions={
                        <>
                            <Select
                                value={filters.kind ?? 'all'}
                                onValueChange={(v) =>
                                    go({ kind: v === 'all' ? null : v })
                                }
                            >
                                <SelectTrigger className="min-w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All kinds
                                    </SelectItem>
                                    {kinds.map((k) => (
                                        <SelectItem
                                            key={k.value}
                                            value={String(k.value)}
                                        >
                                            {k.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                placeholder="Search code or title…"
                                defaultValue={filters.search}
                                className="w-56"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        go({
                                            search: (
                                                e.target as HTMLInputElement
                                            ).value,
                                        });
                                    }
                                }}
                            />
                            {can.create && (
                                <Button
                                    size="sm"
                                    onClick={() => setOpen((o) => !o)}
                                >
                                    <FilePlus2 className="size-4" />
                                    New version
                                </Button>
                            )}
                        </>
                    }
                />

                {open && can.create && (
                    <form
                        onSubmit={submit}
                        className="bg-card grid gap-4 rounded-2xl border p-5 lg:grid-cols-4"
                    >
                        <div>
                            <Label htmlFor="d-code">Document code</Label>
                            <Input
                                id="d-code"
                                className="mt-1"
                                placeholder="Blank for a new document; existing code for a new version"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                            <InputError message={form.errors.code} />
                        </div>
                        <div>
                            <Label>Kind</Label>
                            <Select
                                value={form.data.kind}
                                onValueChange={(v) => form.setData('kind', v)}
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {kinds.map((k) => (
                                        <SelectItem
                                            key={k.value}
                                            value={String(k.value)}
                                        >
                                            {k.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.kind} />
                        </div>
                        <div className="lg:col-span-2">
                            <Label htmlFor="d-title">Title</Label>
                            <Input
                                id="d-title"
                                className="mt-1"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.title} />
                        </div>
                        <div>
                            <Label>Product / material</Label>
                            <Select
                                value={form.data.item_id || 'none'}
                                onValueChange={(v) =>
                                    form.setData(
                                        'item_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue placeholder="None" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">None</SelectItem>
                                    {items.map((i) => (
                                        <SelectItem
                                            key={i.value}
                                            value={String(i.value)}
                                        >
                                            {i.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label>Client</Label>
                            <Select
                                value={form.data.client_id || 'none'}
                                onValueChange={(v) =>
                                    form.setData(
                                        'client_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue placeholder="None" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">None</SelectItem>
                                    {clients.map((c) => (
                                        <SelectItem
                                            key={c.value}
                                            value={String(c.value)}
                                        >
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div>
                            <Label htmlFor="d-eff">Effective from</Label>
                            <Input
                                id="d-eff"
                                type="date"
                                className="mt-1"
                                value={form.data.effective_from}
                                onChange={(e) =>
                                    form.setData(
                                        'effective_from',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="d-file">File</Label>
                            <Input
                                id="d-file"
                                type="file"
                                className="mt-1"
                                onChange={(e) =>
                                    form.setData(
                                        'file',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                            <InputError message={form.errors.file} />
                        </div>
                        <div className="lg:col-span-3">
                            <Label htmlFor="d-change">What changed</Label>
                            <Input
                                id="d-change"
                                className="mt-1"
                                value={form.data.change_summary}
                                onChange={(e) =>
                                    form.setData(
                                        'change_summary',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" disabled={form.processing}>
                                Save draft
                            </Button>
                        </div>
                    </form>
                )}

                {groups.length === 0 ? (
                    <p className="text-muted-foreground bg-card rounded-2xl border p-12 text-center text-sm">
                        No controlled document yet.
                    </p>
                ) : (
                    groups.map((g) => (
                        <section
                            key={g.code}
                            className="bg-card rounded-2xl border"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
                                <div>
                                    <h2 className="font-semibold">
                                        <span className="font-mono">
                                            {g.code}
                                        </span>{' '}
                                        · {g.title}
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        {g.kind_label}
                                        {g.item ? ` · ${g.item}` : ''}
                                        {g.client ? ` · ${g.client}` : ''}
                                    </p>
                                </div>
                                {g.current_version ? (
                                    <StatusBadge variant="success">
                                        Current: v{g.current_version}
                                    </StatusBadge>
                                ) : (
                                    <StatusBadge variant="warning">
                                        No approved version
                                    </StatusBadge>
                                )}
                            </div>
                            <ul className="divide-y">
                                {g.versions.map((v) => (
                                    <li
                                        key={v.id}
                                        className={cn(
                                            'flex flex-wrap items-center justify-between gap-3 px-5 py-3 text-sm',
                                            v.status === 'approved' &&
                                                'bg-emerald-500/5',
                                        )}
                                    >
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">
                                                    v{v.version}
                                                </span>
                                                <StatusBadge
                                                    variant={STATUS[v.status]}
                                                >
                                                    {v.status_label}
                                                </StatusBadge>
                                                {v.title !== g.title && (
                                                    <span className="text-muted-foreground">
                                                        {v.title}
                                                    </span>
                                                )}
                                            </div>
                                            <p className="text-muted-foreground mt-0.5 text-xs">
                                                {v.change_summary
                                                    ? `${v.change_summary} · `
                                                    : ''}
                                                by {v.created_by ?? '—'} on{' '}
                                                {v.created_at}
                                                {v.approved_by
                                                    ? ` · approved by ${v.approved_by} on ${v.approved_at}`
                                                    : ''}
                                                {v.effective_from
                                                    ? ` · effective ${v.effective_from}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {v.download && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a href={v.download}>
                                                        <Download className="size-4" />
                                                        {v.file_name ?? 'File'}
                                                    </a>
                                                </Button>
                                            )}
                                            {v.status === 'draft' &&
                                                v.is_author && (
                                                    <span className="text-muted-foreground text-xs">
                                                        someone else must
                                                        approve your draft
                                                    </span>
                                                )}
                                            {v.can_approve && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button size="sm">
                                                            <FileCheck2 className="size-4" />
                                                            Approve
                                                        </Button>
                                                    }
                                                    title={`Approve ${g.code} v${v.version}?`}
                                                    description="It becomes the current version production references; the previous approved version is superseded and kept on file."
                                                    confirmLabel="Approve"
                                                    action={() =>
                                                        router.post(
                                                            approve(v.id).url,
                                                            undefined,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            )}
                                            {v.can_withdraw && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                        >
                                                            <FileX2 className="size-4" />
                                                            Withdraw
                                                        </Button>
                                                    }
                                                    title={`Withdraw ${g.code} v${v.version}?`}
                                                    description="It stays on file, marked withdrawn, and production stops referencing it."
                                                    confirmLabel="Withdraw"
                                                    destructive
                                                    action={() =>
                                                        router.post(
                                                            withdraw(v.id).url,
                                                            undefined,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))
                )}
            </div>
        </>
    );
}

DocumentsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Controlled documents', href: index() },
    ],
};
