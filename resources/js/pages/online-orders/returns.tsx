import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Undo2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import InputError from '@/components/input-error';
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
import { TONE_VARIANT, rupees, when } from '@/lib/dispatch';
import { courierName } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    index as onlineOrders,
    show as showBatch,
} from '@/routes/online-orders';
import {
    claim as claimRoute,
    create as receive,
    index,
} from '@/routes/online-orders/returns';
import type { DispatchTone } from '@/types';

type ReturnLine = {
    item_id: number;
    item: string | null;
    item_code: string | null;
    sent: string;
    good: string;
    damaged: string;
    missing: string;
    good_store: string | null;
    damaged_store: string | null;
};

type ReturnRow = {
    id: number;
    number: string;
    kind: 'rto' | 'customer';
    kind_label: string;
    awb: string | null;
    order_number: string | null;
    courier: string | null;
    batch_id: number | null;
    return_awb: string | null;
    marketplace: string | null;
    brand: string | null;
    facility: string | null;
    received_by: string | null;
    received_at: string;
    notes: string | null;
    wrong_item: boolean;
    claim_status: 'none' | 'open' | 'won' | 'lost';
    claim_label: string;
    claim_tone: DispatchTone;
    claim_deadline_at: string | null;
    claim_overdue: boolean;
    claim_reference: string | null;
    claim_amount: string | null;
    claim_note: string | null;
    lines: ReturnLine[];
};

type Summary = {
    returns: number;
    rto: number;
    customer: number;
    good: string;
    damaged: string;
    missing: string;
    claims_open: number;
    claims_overdue: number;
};

type Filters = {
    claim: 'all' | 'open' | 'overdue';
    marketplace: number | null;
    from: string;
    to: string;
};

type Can = { receive: boolean; claim: boolean };

const selectClass =
    'border-input bg-background h-9 rounded-md border px-3 text-sm shadow-xs';

function ClaimDialog({
    row,
    statuses,
    onClose,
}: {
    row: ReturnRow;
    statuses: { value: string; label: string }[];
    onClose: () => void;
}) {
    const form = useForm({
        claim_status: row.claim_status,
        claim_reference: row.claim_reference ?? '',
        claim_amount: row.claim_amount ?? '',
        claim_note: row.claim_note ?? '',
    });

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Claim for {row.number}</DialogTitle>
                    <DialogDescription>
                        Record what the marketplace said about the damaged,
                        missing or wrong goods.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.patch(claimRoute(row.id).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="claim_status">Claim status</Label>
                        <select
                            id="claim_status"
                            className={cn(selectClass, 'h-10 w-full')}
                            value={form.data.claim_status}
                            onChange={(e) =>
                                form.setData(
                                    'claim_status',
                                    e.target.value as ReturnRow['claim_status'],
                                )
                            }
                        >
                            {statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.claim_status} />
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="claim_reference">
                                Claim / ticket no.
                            </Label>
                            <Input
                                id="claim_reference"
                                value={form.data.claim_reference}
                                onChange={(e) =>
                                    form.setData(
                                        'claim_reference',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="claim_amount">Amount (₹)</Label>
                            <Input
                                id="claim_amount"
                                type="number"
                                min={0}
                                step="0.01"
                                value={form.data.claim_amount}
                                onChange={(e) =>
                                    form.setData('claim_amount', e.target.value)
                                }
                            />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="claim_note">Note</Label>
                        <Input
                            id="claim_note"
                            value={form.data.claim_note}
                            onChange={(e) =>
                                form.setData('claim_note', e.target.value)
                            }
                        />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save claim
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Kpi({
    label,
    value,
    hint,
    tone,
    onClick,
    active,
    className: extra,
}: {
    label: string;
    value: string | number;
    hint?: ReactNode;
    tone?: 'danger' | 'warning' | 'success';
    onClick?: () => void;
    active?: boolean;
    className?: string;
}) {
    const body = (
        <>
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                {label}
            </p>
            <p
                className={cn(
                    'mt-1 text-2xl font-semibold tabular-nums',
                    tone === 'danger' && 'text-red-700 dark:text-red-300',
                    tone === 'warning' && 'text-amber-700 dark:text-amber-300',
                    tone === 'success' &&
                        'text-emerald-700 dark:text-emerald-300',
                )}
            >
                {value}
            </p>
            {hint && (
                <p className="text-muted-foreground mt-0.5 text-xs">{hint}</p>
            )}
        </>
    );
    const className = cn(
        'bg-card flex flex-col items-start justify-start rounded-xl border p-4 text-left',
        extra,
        onClick && 'hover:border-primary/50 transition-colors',
        active && 'border-primary ring-primary/30 ring-2',
    );

    return onClick ? (
        <button type="button" onClick={onClick} className={className}>
            {body}
        </button>
    ) : (
        <div className={className}>{body}</div>
    );
}

function Qty({
    n,
    label,
    tone,
}: {
    n: string;
    label: string;
    tone: 'sent' | 'good' | 'damaged' | 'missing';
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium tabular-nums',
                tone === 'sent' && 'bg-muted text-muted-foreground',
                tone === 'good' &&
                    'bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
                tone === 'damaged' &&
                    'bg-red-500/10 text-red-800 dark:text-red-200',
                tone === 'missing' &&
                    'bg-amber-500/15 text-amber-900 dark:text-amber-100',
            )}
        >
            {Number(n)} {label}
        </span>
    );
}

function WhatCameBack({ r }: { r: ReturnRow }) {
    return (
        <div className="space-y-1.5">
            {r.lines.map((l) => (
                <div key={l.item_id}>
                    <div className="text-sm font-medium">{l.item}</div>
                    <div className="mt-0.5 flex flex-wrap gap-1">
                        <Qty n={l.sent} label="sent" tone="sent" />
                        {Number(l.good) > 0 && (
                            <Qty n={l.good} label="good" tone="good" />
                        )}
                        {Number(l.damaged) > 0 && (
                            <Qty n={l.damaged} label="damaged" tone="damaged" />
                        )}
                        {Number(l.missing) > 0 && (
                            <Qty
                                n={l.missing}
                                label="not received"
                                tone="missing"
                            />
                        )}
                    </div>
                </div>
            ))}
            {r.wrong_item && (
                <p className="text-xs font-medium text-red-700 dark:text-red-300">
                    Wrong item came back
                </p>
            )}
            {r.notes && (
                <p className="text-muted-foreground text-xs">“{r.notes}”</p>
            )}
        </div>
    );
}

function ParcelCell({ r }: { r: ReturnRow }) {
    const code = r.awb ?? r.order_number;

    return (
        <div className="min-w-0">
            <div className="font-mono text-sm">
                {r.batch_id ? (
                    <Link
                        href={showBatch(r.batch_id)}
                        className="underline-offset-4 hover:underline"
                    >
                        {code}
                    </Link>
                ) : (
                    code
                )}
            </div>
            <div className="text-muted-foreground text-xs">
                {[r.brand, r.marketplace, courierName(r.courier)]
                    .filter(Boolean)
                    .join(' · ')}
            </div>
            {r.return_awb && (
                <div className="text-muted-foreground font-mono text-xs">
                    returned as {r.return_awb}
                </div>
            )}
        </div>
    );
}

function ClaimCell({ r }: { r: ReturnRow }) {
    return (
        <div className="space-y-1">
            <StatusBadge
                variant={
                    TONE_VARIANT[r.claim_overdue ? 'danger' : r.claim_tone]
                }
            >
                {r.claim_overdue ? 'Claim overdue' : r.claim_label}
            </StatusBadge>
            {r.claim_deadline_at && r.claim_status === 'open' && (
                <div
                    className={cn(
                        'text-xs',
                        r.claim_overdue
                            ? 'font-medium text-red-700 dark:text-red-300'
                            : 'text-muted-foreground',
                    )}
                >
                    Raise by {when(r.claim_deadline_at)}
                </div>
            )}
            {r.claim_reference && (
                <div className="text-muted-foreground text-xs">
                    {r.claim_reference}
                    {r.claim_amount ? ` · ${rupees(r.claim_amount)}` : ''}
                </div>
            )}
        </div>
    );
}

export default function Returns({
    returns,
    summary,
    filters,
    marketplaces,
    claim_statuses,
    can,
}: {
    returns: ReturnRow[];
    summary: Summary;
    filters: Filters;
    marketplaces: { value: string; label: string }[];
    claim_statuses: { value: string; label: string }[];
    can: Can;
}) {
    const [claiming, setClaiming] = useState<ReturnRow | null>(null);
    const go = (patch: Partial<Filters>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({ ...filters, ...patch }).filter(
                    ([, v]) => v !== null && v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true },
        );

    const canUpdate = (r: ReturnRow) => can.claim && r.claim_status !== 'none';

    const tabs: [Filters['claim'], string, number | null][] = [
        ['all', 'All returns', null],
        ['open', 'Claims to raise', summary.claims_open],
        ['overdue', 'Overdue', summary.claims_overdue],
    ];

    return (
        <>
            <Head title="Returns · Online orders" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Returns"
                    description="Parcels that came back. Good pieces go back on the shelf, damaged ones to the damaged goods store, and claims are raised on the marketplace."
                    actions={
                        can.receive && (
                            <Button asChild>
                                <Link href={receive()}>
                                    <Undo2 className="size-4" />
                                    Receive a return
                                </Link>
                            </Button>
                        )
                    }
                />

                {summary.claims_overdue > 0 && filters.claim !== 'overdue' && (
                    <div className="flex flex-col gap-3 rounded-xl border border-red-600/30 bg-red-500/10 p-4 text-sm sm:flex-row sm:items-center">
                        <AlertTriangle className="size-5 shrink-0 text-red-600" />
                        <p className="flex-1">
                            <b>
                                {summary.claims_overdue}{' '}
                                {summary.claims_overdue === 1
                                    ? 'claim is'
                                    : 'claims are'}
                            </b>{' '}
                            past the marketplace&rsquo;s deadline and not raised
                            yet.
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => go({ claim: 'overdue' })}
                        >
                            Show overdue claims
                        </Button>
                    </div>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    <Kpi
                        label="Returns"
                        value={summary.returns}
                        hint={`${summary.rto} RTO · ${summary.customer} customer`}
                    />
                    <Kpi
                        label="Back on shelf"
                        value={summary.good}
                        hint="pieces, into their batches"
                        tone={Number(summary.good) > 0 ? 'success' : undefined}
                    />
                    <Kpi
                        label="Damaged"
                        value={summary.damaged}
                        hint="pieces, in damaged goods store"
                        tone={
                            Number(summary.damaged) > 0 ? 'danger' : undefined
                        }
                    />
                    <Kpi
                        label="Not received"
                        value={summary.missing}
                        hint="pieces missing from the packet"
                        tone={
                            Number(summary.missing) > 0 ? 'warning' : undefined
                        }
                    />
                    <Kpi
                        label="Claims to raise"
                        value={summary.claims_open}
                        hint={
                            summary.claims_overdue > 0
                                ? `${summary.claims_overdue} overdue`
                                : 'none overdue'
                        }
                        tone={
                            summary.claims_overdue > 0
                                ? 'danger'
                                : summary.claims_open > 0
                                  ? 'warning'
                                  : undefined
                        }
                        onClick={() => go({ claim: 'open' })}
                        active={filters.claim === 'open'}
                        className="col-span-2 md:col-span-1"
                    />
                </div>

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex flex-col gap-3 border-b p-3 lg:flex-row lg:items-center lg:justify-between">
                        <div
                            role="tablist"
                            aria-label="Show"
                            className="bg-muted inline-flex w-full rounded-lg p-1 sm:w-auto"
                        >
                            {tabs.map(([key, label, count]) => (
                                <button
                                    key={key}
                                    type="button"
                                    role="tab"
                                    aria-selected={filters.claim === key}
                                    onClick={() => go({ claim: key })}
                                    className={cn(
                                        'flex flex-1 items-center justify-center gap-1.5 rounded-md px-2 py-1.5 text-xs font-medium whitespace-nowrap sm:flex-none sm:px-3 sm:text-sm',
                                        filters.claim === key
                                            ? 'bg-background text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {label}
                                    {count !== null && count > 0 && (
                                        <span
                                            className={cn(
                                                'rounded-full px-1.5 text-xs tabular-nums',
                                                key === 'overdue'
                                                    ? 'bg-red-600 text-white'
                                                    : 'bg-amber-500/20 text-amber-900 dark:text-amber-100',
                                            )}
                                        >
                                            {count}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <select
                                className={cn(selectClass, 'w-full sm:w-auto')}
                                value={filters.marketplace ?? ''}
                                onChange={(e) =>
                                    go({
                                        marketplace: e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    })
                                }
                                aria-label="Marketplace"
                            >
                                <option value="">All marketplaces</option>
                                {marketplaces.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </select>
                            {filters.claim === 'all' ? (
                                <div className="flex w-full items-center gap-2 sm:w-auto">
                                    <Input
                                        type="date"
                                        value={filters.from}
                                        onChange={(e) =>
                                            go({ from: e.target.value })
                                        }
                                        className="h-9 min-w-0 flex-1 px-2 text-sm sm:w-40 sm:flex-none sm:px-3"
                                        aria-label="Received from"
                                    />
                                    <span className="text-muted-foreground text-sm">
                                        –
                                    </span>
                                    <Input
                                        type="date"
                                        value={filters.to}
                                        onChange={(e) =>
                                            go({ to: e.target.value })
                                        }
                                        className="h-9 min-w-0 flex-1 px-2 text-sm sm:w-40 sm:flex-none sm:px-3"
                                        aria-label="Received to"
                                    />
                                </div>
                            ) : (
                                <span className="text-muted-foreground text-xs">
                                    Every open claim, whatever the date
                                </span>
                            )}
                        </div>
                    </div>

                    {returns.length === 0 ? (
                        <div className="px-4 py-12 text-center">
                            <p className="font-medium">No returns here</p>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {filters.claim === 'all'
                                    ? 'Nothing came back in these dates.'
                                    : 'No claim is waiting to be raised.'}
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="hidden overflow-x-auto md:block">
                                <table className="w-full text-sm">
                                    <thead className="text-muted-foreground bg-muted/40 text-left text-xs uppercase">
                                        <tr className="border-b">
                                            <th className="min-w-[15rem] px-4 py-2.5 font-medium">
                                                Return
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Parcel
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                What came back
                                            </th>
                                            <th className="px-4 py-2.5 font-medium">
                                                Claim
                                            </th>
                                            {can.claim && (
                                                <th className="w-px px-4 py-2.5 text-right font-medium">
                                                    <span className="sr-only">
                                                        Actions
                                                    </span>
                                                </th>
                                            )}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {returns.map((r) => (
                                            <tr
                                                key={r.id}
                                                className="hover:bg-muted/30 align-top"
                                            >
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-2 whitespace-nowrap">
                                                        <span className="font-mono font-medium">
                                                            {r.number}
                                                        </span>
                                                        <StatusBadge
                                                            variant={
                                                                r.kind === 'rto'
                                                                    ? 'info'
                                                                    : 'muted'
                                                            }
                                                        >
                                                            {r.kind === 'rto'
                                                                ? 'RTO'
                                                                : 'Customer'}
                                                        </StatusBadge>
                                                    </div>
                                                    <div className="text-muted-foreground mt-0.5 text-xs">
                                                        {when(r.received_at)}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {[
                                                            r.received_by,
                                                            r.facility,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <ParcelCell r={r} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <WhatCameBack r={r} />
                                                </td>
                                                <td className="px-4 py-3">
                                                    <ClaimCell r={r} />
                                                </td>
                                                {can.claim && (
                                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                                        {canUpdate(r) && (
                                                            <Button
                                                                variant="outline"
                                                                size="sm"
                                                                onClick={() =>
                                                                    setClaiming(
                                                                        r,
                                                                    )
                                                                }
                                                            >
                                                                Update claim
                                                            </Button>
                                                        )}
                                                    </td>
                                                )}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <ul className="divide-y md:hidden">
                                {returns.map((r) => (
                                    <li key={r.id} className="space-y-3 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="flex items-center gap-2">
                                                    <span className="font-mono font-medium">
                                                        {r.number}
                                                    </span>
                                                    <StatusBadge
                                                        variant={
                                                            r.kind === 'rto'
                                                                ? 'info'
                                                                : 'muted'
                                                        }
                                                    >
                                                        {r.kind === 'rto'
                                                            ? 'RTO'
                                                            : 'Customer'}
                                                    </StatusBadge>
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {when(r.received_at)}
                                                    {r.received_by
                                                        ? ` · ${r.received_by}`
                                                        : ''}
                                                </div>
                                            </div>
                                            <ClaimCell r={r} />
                                        </div>
                                        <ParcelCell r={r} />
                                        <WhatCameBack r={r} />
                                        {canUpdate(r) && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="w-full"
                                                onClick={() => setClaiming(r)}
                                            >
                                                Update claim
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </section>
            </div>

            {claiming && (
                <ClaimDialog
                    row={claiming}
                    statuses={claim_statuses}
                    onClose={() => setClaiming(null)}
                />
            )}
        </>
    );
}

Returns.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Online orders', href: onlineOrders() },
        { title: 'Returns', href: index() },
    ],
};
