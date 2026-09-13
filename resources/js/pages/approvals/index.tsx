import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ShieldCheck, Signature, XCircle } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { approve, index, reject } from '@/routes/approvals';

type Action = {
    id: number;
    user: string | null;
    action: string;
    comment: string | null;
    acted_at: string | null;
    ip: string | null;
    signature: string | null;
    verified: boolean;
};

type ApprovalRow = {
    id: number;
    workflow: string;
    workflow_label: string;
    subject: string;
    href: string | null;
    status: 'pending' | 'approved' | 'rejected' | 'returned' | 'cancelled';
    requested_by: string | null;
    requested_at: string | null;
    note: string | null;
    triggers: { key: string; reason: string }[];
    completed_at: string | null;
    can_act: boolean;
    actions: Action[];
};

const STATUS = {
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
    returned: 'info',
    cancelled: 'muted',
} as const;

function when(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('en-IN', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function Decide({ row }: { row: ApprovalRow }) {
    const [mode, setMode] = useState<'approve' | 'reject' | null>(null);
    const [comment, setComment] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = () => {
        if (!mode) return;
        setBusy(true);
        router.post(
            (mode === 'approve' ? approve(row.id) : reject(row.id)).url,
            { comment },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setMode(null);
                    setComment('');
                },
            },
        );
    };

    return (
        <>
            <div className="flex gap-2">
                <Button size="sm" onClick={() => setMode('approve')}>
                    <CheckCircle2 className="size-4" />
                    Approve &amp; sign
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setMode('reject')}
                >
                    <XCircle className="size-4" />
                    Reject
                </Button>
            </div>
            <Dialog
                open={mode !== null}
                onOpenChange={(o) => !o && setMode(null)}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {mode === 'approve' ? 'Approve and sign' : 'Reject'}
                            : {row.workflow_label}
                        </DialogTitle>
                        <DialogDescription>
                            {row.subject}. Raised by {row.requested_by ?? '—'}.
                            {mode === 'approve'
                                ? ' Your decision is recorded with a digital signature and the operation is carried out in your name.'
                                : ' Say why; the requester is told.'}
                        </DialogDescription>
                    </DialogHeader>
                    {row.triggers.length > 0 && (
                        <ul className="space-y-1 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-sm">
                            {row.triggers.map((t, i) => (
                                <li key={i}>• {t.reason}</li>
                            ))}
                        </ul>
                    )}
                    <div>
                        <Label htmlFor={`c-${row.id}`}>
                            {mode === 'approve'
                                ? 'Comment (optional)'
                                : 'Reason'}
                        </Label>
                        <Input
                            id={`c-${row.id}`}
                            className="mt-1"
                            value={comment}
                            onChange={(e) => setComment(e.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setMode(null)}>
                            Cancel
                        </Button>
                        <Button
                            onClick={submit}
                            disabled={
                                busy || (mode === 'reject' && !comment.trim())
                            }
                            variant={
                                mode === 'reject' ? 'destructive' : 'default'
                            }
                        >
                            {mode === 'approve' ? 'Sign and approve' : 'Reject'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Row({ row, actionable }: { row: ApprovalRow; actionable: boolean }) {
    return (
        <li className="px-5 py-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge variant={STATUS[row.status]}>
                            {row.status}
                        </StatusBadge>
                        <span className="font-medium">
                            {row.workflow_label}
                        </span>
                        <span className="text-muted-foreground">·</span>
                        {row.href ? (
                            <Link
                                href={row.href}
                                className="underline-offset-4 hover:underline"
                            >
                                {row.subject}
                            </Link>
                        ) : (
                            <span>{row.subject}</span>
                        )}
                    </div>
                    <p className="text-muted-foreground mt-1 text-xs">
                        Raised by {row.requested_by ?? '—'} ·{' '}
                        {when(row.requested_at)}
                        {row.note ? ` · “${row.note}”` : ''}
                    </p>
                    {row.triggers.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-sm">
                            {row.triggers.map((t, i) => (
                                <li key={i} className="flex items-start gap-2">
                                    <span className="mt-2 size-1.5 shrink-0 rounded-full bg-amber-500" />
                                    <span>{t.reason}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {row.actions.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-xs">
                            {row.actions.map((a) => (
                                <li
                                    key={a.id}
                                    className="text-muted-foreground flex flex-wrap items-center gap-2"
                                >
                                    <Signature className="size-3" />
                                    <span className="text-foreground font-medium">
                                        {a.user ?? '—'}
                                    </span>
                                    {a.action} · {when(a.acted_at)}
                                    {a.comment ? ` · “${a.comment}”` : ''}
                                    {a.signature && (
                                        <span
                                            className={cn(
                                                'rounded border px-1 font-mono',
                                                a.verified
                                                    ? 'border-emerald-600/30 text-emerald-700 dark:text-emerald-300'
                                                    : 'border-red-600/30 text-red-700',
                                            )}
                                            title={
                                                a.verified
                                                    ? 'Signature verified'
                                                    : 'Signature does not verify'
                                            }
                                        >
                                            {a.signature}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
                {actionable && row.can_act && row.status === 'pending' && (
                    <Decide row={row} />
                )}
            </div>
        </li>
    );
}

export default function ApprovalsIndex({
    mine,
    recent,
    workflows,
    can,
}: {
    mine: ApprovalRow[];
    recent: ApprovalRow[];
    workflows: {
        key: string;
        label: string;
        description: string;
        always: boolean;
    }[];
    can: { act: boolean };
}) {
    return (
        <>
            <Head title="Approvals" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Approvals"
                    description="Maker-checker on the operations that matter: one person raises, another signs. Every decision is recorded with a digital signature that can be verified later and is never edited."
                />

                <section className="bg-card rounded-2xl border">
                    <div className="flex items-center justify-between border-b px-5 py-4">
                        <h2 className="inline-flex items-center gap-2 font-semibold">
                            <ShieldCheck className="text-primary size-4" />
                            Waiting for your signature
                        </h2>
                        <span className="text-2xl font-semibold tabular-nums">
                            {mine.length}
                        </span>
                    </div>
                    {mine.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-8 text-center text-sm">
                            Nothing needs your signature.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {mine.map((row) => (
                                <Row
                                    key={row.id}
                                    row={row}
                                    actionable={can.act}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="bg-card rounded-2xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Recent requests</h2>
                        <p className="text-muted-foreground text-sm">
                            The last fifty, whoever raised or signed them.
                        </p>
                    </div>
                    {recent.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-8 text-center text-sm">
                            No request has been raised yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {recent.map((row) => (
                                <Row
                                    key={row.id}
                                    row={row}
                                    actionable={false}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="bg-card rounded-2xl border p-5">
                    <h2 className="font-semibold">
                        What needs a second signature
                    </h2>
                    <ul className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {workflows.map((w) => (
                            <li
                                key={w.key}
                                className="rounded-xl border p-3 text-sm"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium">
                                        {w.label}
                                    </span>
                                    <StatusBadge
                                        variant={w.always ? 'info' : 'muted'}
                                    >
                                        {w.always ? 'always' : 'by risk'}
                                    </StatusBadge>
                                </div>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {w.description}
                                </p>
                            </li>
                        ))}
                    </ul>
                    <p className="text-muted-foreground mt-3 text-xs">
                        By risk: a non-standard recipe, a negative margin, a
                        product below its yield target, abnormal wastage, an
                        unexpected price increase, or the requester being the
                        author. Whoever raises a request can never approve it.
                    </p>
                </section>
            </div>
        </>
    );
}

ApprovalsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Approvals', href: index() },
    ],
};
