import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Undo2 } from 'lucide-react';
import { useState } from 'react';
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
    lines: {
        item_id: number;
        item: string | null;
        item_code: string | null;
        sent: string;
        good: string;
        damaged: string;
        missing: string;
        good_store: string | null;
        damaged_store: string | null;
    }[];
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
                        What the marketplace said about the damaged, missing or
                        wrong goods.
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
                    className="space-y-3"
                >
                    <div className="space-y-1">
                        <Label htmlFor="claim_status">Claim</Label>
                        <select
                            id="claim_status"
                            className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
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
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1">
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
                        <div className="space-y-1">
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
                    <div className="space-y-1">
                        <Label htmlFor="claim_note">Note</Label>
                        <Input
                            id="claim_note"
                            value={form.data.claim_note}
                            onChange={(e) =>
                                form.setData('claim_note', e.target.value)
                            }
                        />
                        <InputError message={form.errors.claim_status} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Back
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Tile({
    label,
    value,
    tone,
}: {
    label: string;
    value: string | number;
    tone?: 'danger' | 'warning' | 'success';
}) {
    return (
        <div
            className={cn(
                'bg-card rounded-xl border p-4',
                tone === 'danger' && 'border-red-600/40 bg-red-500/5',
                tone === 'warning' && 'border-amber-600/40 bg-amber-500/5',
            )}
        >
            <p className="text-muted-foreground text-xs tracking-wide uppercase">
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
    can: { receive: boolean; claim: boolean };
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

    return (
        <>
            <Head title="Returns · Online orders" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Returns"
                    description="Online orders that went out and came back: what was sellable and went back on the shelf, what was damaged and went to the damaged goods store, and the claims to raise on the marketplaces."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={onlineOrders()}>Online orders</Link>
                            </Button>
                            {can.receive && (
                                <Button asChild>
                                    <Link href={receive()}>
                                        <Undo2 className="size-4" />
                                        Receive a return
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {summary.claims_overdue > 0 && (
                    <button
                        type="button"
                        onClick={() => go({ claim: 'overdue' })}
                        className="flex w-full items-start gap-3 rounded-xl border border-red-600/30 bg-red-500/10 p-4 text-left text-sm"
                    >
                        <AlertTriangle className="size-5 shrink-0 text-red-600" />
                        <span>
                            <b>{summary.claims_overdue} claim(s)</b> are past
                            the marketplace&rsquo;s deadline and still not
                            raised. Show them.
                        </span>
                    </button>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                    <Tile label="Returns" value={summary.returns} />
                    <Tile label="RTO" value={summary.rto} />
                    <Tile label="Customer" value={summary.customer} />
                    <Tile
                        label="Back on shelf"
                        value={summary.good}
                        tone="success"
                    />
                    <Tile
                        label="Damaged"
                        value={summary.damaged}
                        tone={
                            Number(summary.damaged) > 0 ? 'danger' : undefined
                        }
                    />
                    <Tile
                        label="Not received"
                        value={summary.missing}
                        tone={
                            Number(summary.missing) > 0 ? 'warning' : undefined
                        }
                    />
                    <Tile
                        label="Claims to raise"
                        value={summary.claims_open}
                        tone={summary.claims_open > 0 ? 'warning' : undefined}
                    />
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    {(
                        [
                            ['all', 'All'],
                            ['open', 'Claims to raise'],
                            ['overdue', 'Claims overdue'],
                        ] as const
                    ).map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => go({ claim: key })}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs',
                                filters.claim === key
                                    ? 'bg-primary text-primary-foreground border-primary'
                                    : 'hover:bg-muted',
                            )}
                        >
                            {label}
                        </button>
                    ))}
                    <select
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
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
                    {filters.claim === 'all' && (
                        <>
                            <Input
                                type="date"
                                value={filters.from}
                                onChange={(e) => go({ from: e.target.value })}
                                className="w-40"
                                aria-label="From"
                            />
                            <span className="text-muted-foreground text-sm">
                                to
                            </span>
                            <Input
                                type="date"
                                value={filters.to}
                                onChange={(e) => go({ to: e.target.value })}
                                className="w-40"
                                aria-label="To"
                            />
                        </>
                    )}
                </div>

                <section className="bg-card rounded-xl border">
                    {returns.length === 0 ? (
                        <p className="text-muted-foreground px-4 py-10 text-center text-sm">
                            No returns here.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[60rem] text-sm">
                                <thead className="text-muted-foreground text-left text-xs uppercase">
                                    <tr className="border-b">
                                        <th className="px-4 py-2 font-medium">
                                            Return
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Parcel
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            What came back
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Claim
                                        </th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {returns.map((r) => (
                                        <tr key={r.id} className="align-top">
                                            <td className="px-4 py-3">
                                                <div className="font-mono font-medium">
                                                    {r.number}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {r.kind_label}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {when(r.received_at)} ·{' '}
                                                    {r.received_by ?? '—'}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {r.facility}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-mono">
                                                    {r.batch_id ? (
                                                        <Link
                                                            href={showBatch(
                                                                r.batch_id,
                                                            )}
                                                            className="underline-offset-4 hover:underline"
                                                        >
                                                            {r.awb ??
                                                                r.order_number}
                                                        </Link>
                                                    ) : (
                                                        (r.awb ??
                                                        r.order_number)
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {r.brand} · {r.marketplace}{' '}
                                                    · {courierName(r.courier)}
                                                </div>
                                                {r.return_awb && (
                                                    <div className="text-muted-foreground font-mono text-xs">
                                                        came back as{' '}
                                                        {r.return_awb}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {r.lines.map((l) => (
                                                    <div key={l.item_id}>
                                                        <span className="font-medium">
                                                            {l.item}
                                                        </span>
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            · of{' '}
                                                            {Number(l.sent)}:
                                                        </span>{' '}
                                                        <span className="text-emerald-700 dark:text-emerald-300">
                                                            {Number(l.good)}{' '}
                                                            good
                                                        </span>
                                                        {Number(l.damaged) >
                                                            0 && (
                                                            <span className="text-red-700 dark:text-red-300">
                                                                ,{' '}
                                                                {Number(
                                                                    l.damaged,
                                                                )}{' '}
                                                                damaged
                                                            </span>
                                                        )}
                                                        {Number(l.missing) >
                                                            0 && (
                                                            <span className="text-amber-700 dark:text-amber-300">
                                                                ,{' '}
                                                                {Number(
                                                                    l.missing,
                                                                )}{' '}
                                                                not received
                                                            </span>
                                                        )}
                                                    </div>
                                                ))}
                                                {r.wrong_item && (
                                                    <div className="text-xs font-medium text-red-700 dark:text-red-300">
                                                        Wrong item came back
                                                    </div>
                                                )}
                                                {r.notes && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {r.notes}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    variant={
                                                        TONE_VARIANT[
                                                            r.claim_overdue
                                                                ? 'danger'
                                                                : r.claim_tone
                                                        ]
                                                    }
                                                >
                                                    {r.claim_overdue
                                                        ? 'Claim overdue'
                                                        : r.claim_label}
                                                </StatusBadge>
                                                {r.claim_deadline_at &&
                                                    r.claim_status ===
                                                        'open' && (
                                                        <div
                                                            className={cn(
                                                                'mt-1 text-xs',
                                                                r.claim_overdue
                                                                    ? 'text-red-700 dark:text-red-300'
                                                                    : 'text-muted-foreground',
                                                            )}
                                                        >
                                                            by{' '}
                                                            {when(
                                                                r.claim_deadline_at,
                                                            )}
                                                        </div>
                                                    )}
                                                {r.claim_reference && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {r.claim_reference}
                                                        {r.claim_amount
                                                            ? ` · ${rupees(r.claim_amount)}`
                                                            : ''}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {can.claim &&
                                                    r.claim_status !==
                                                        'none' && (
                                                        <Button
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() =>
                                                                setClaiming(r)
                                                            }
                                                        >
                                                            Update claim
                                                        </Button>
                                                    )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
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
