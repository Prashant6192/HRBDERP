import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, PackageCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { postJson } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { catchUp, index as onlineOrders } from '@/routes/online-orders';
import { store } from '@/routes/online-orders/catch-up';
import { index as stockIndex } from '@/routes/stock';

type Parcel = {
    id: number;
    day: string;
    awb: string | null;
    order_number: string | null;
    marketplace: string | null;
    brand: string | null;
    courier: string | null;
    status: string;
    status_label: string;
    stock_state: string;
    stock_label: string;
    contents: string;
    pieces: { item: string; units: number }[];
};

type Result = {
    done: number;
    skipped: { id: number; reference: string; why: string }[];
};

const CHUNK = 100;

const ready = (p: Parcel) => p.stock_state === 'reserved';

const dayLabel = (day: string) =>
    new Date(`${day}T00:00:00`).toLocaleDateString('en-IN', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

export default function CatchUp({
    from,
    to,
    more,
    parcels,
}: {
    from: string;
    to: string;
    more: boolean;
    parcels: Parcel[];
}) {
    const [range, setRange] = useState({ from, to });
    const [ticked, setTicked] = useState<Set<number>>(
        () => new Set(parcels.filter(ready).map((p) => p.id)),
    );
    const [open, setOpen] = useState<Set<string>>(new Set());
    const [running, setRunning] = useState<{
        done: number;
        total: number;
    } | null>(null);
    const [result, setResult] = useState<Result | null>(null);

    const days = useMemo(() => {
        const byDay = new Map<string, Parcel[]>();

        for (const p of parcels) {
            byDay.set(p.day, [...(byDay.get(p.day) ?? []), p]);
        }

        return [...byDay.entries()].sort(([a], [b]) => a.localeCompare(b));
    }, [parcels]);

    const counts = {
        all: parcels.length,
        ready: parcels.filter(ready).length,
        short: parcels.filter((p) => p.stock_state === 'short').length,
        unmapped: parcels.filter((p) => p.stock_state === 'unmapped').length,
    };

    const toggle = (ids: number[], on: boolean) =>
        setTicked((current) => {
            const next = new Set(current);
            ids.forEach((id) => (on ? next.add(id) : next.delete(id)));

            return next;
        });

    const show = () =>
        router.get(catchUp().url, range, { preserveState: false });

    const run = async () => {
        const ids = [...ticked];

        if (
            ids.length === 0 ||
            !window.confirm(
                `Mark ${ids.length} parcel(s) as packed and dispatched, dated the day of their labels? Their stock leaves the store. Do this only for parcels the courier really took.`,
            )
        ) {
            return;
        }

        const total: Result = { done: 0, skipped: [] };
        setResult(null);
        setRunning({ done: 0, total: ids.length });

        try {
            for (let i = 0; i < ids.length; i += CHUNK) {
                const { ok, data } = await postJson<
                    Result & { message?: string }
                >(store().url, { shipment_ids: ids.slice(i, i + CHUNK) });

                if (!ok) {
                    total.skipped.push({
                        id: 0,
                        reference: `Parcels ${i + 1}–${Math.min(i + CHUNK, ids.length)}`,
                        why: data.message ?? 'The server refused this run.',
                    });
                } else {
                    total.done += data.done;
                    total.skipped.push(...data.skipped);
                }

                setRunning({
                    done: Math.min(i + CHUNK, ids.length),
                    total: ids.length,
                });
            }
        } finally {
            setRunning(null);
            setResult(total);
            router.reload({
                only: ['parcels', 'more'],
                onSuccess: (page) =>
                    setTicked(
                        new Set(
                            (page.props.parcels as Parcel[])
                                .filter(ready)
                                .map((p) => p.id),
                        ),
                    ),
            });
        }
    };

    return (
        <>
            <Head title="Catch up · Online orders" />
            <div className="space-y-6 p-4 pb-0 sm:p-6 sm:pb-0">
                <PageHeader
                    title="Catch up"
                    description="Parcels whose labels were printed and which went out with the courier without being scanned. Tick the ones that really left: each is marked packed and dispatched, dated the day of its labels, and its stock leaves the store."
                />

                <section className="bg-card flex flex-wrap items-end gap-3 rounded-xl border p-4">
                    <div className="space-y-1.5">
                        <Label htmlFor="from">Labels from</Label>
                        <Input
                            id="from"
                            type="date"
                            value={range.from}
                            onChange={(e) =>
                                setRange({ ...range, from: e.target.value })
                            }
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="to">to</Label>
                        <Input
                            id="to"
                            type="date"
                            value={range.to}
                            onChange={(e) =>
                                setRange({ ...range, to: e.target.value })
                            }
                        />
                    </div>
                    <Button variant="outline" onClick={show}>
                        Show
                    </Button>
                    <div className="ml-auto flex flex-wrap gap-4 text-sm">
                        <span>
                            <b>{counts.all}</b> not scanned
                        </span>
                        <span className="text-emerald-700 dark:text-emerald-300">
                            <b>{counts.ready}</b> stock held
                        </span>
                        <span className="text-red-700 dark:text-red-300">
                            <b>{counts.short}</b> short
                        </span>
                        <span className="text-amber-700 dark:text-amber-300">
                            <b>{counts.unmapped}</b> not mapped
                        </span>
                    </div>
                </section>

                {(counts.short > 0 || counts.unmapped > 0) && (
                    <p className="rounded-lg bg-amber-500/15 p-3 text-sm text-amber-900 dark:text-amber-100">
                        Short parcels cannot be caught up until their stock is
                        in the ERP: scan the stock that came in on the{' '}
                        <Link
                            href={stockIndex()}
                            className="font-medium underline"
                        >
                            Stock
                        </Link>{' '}
                        screen, then come back. Parcels not mapped need their
                        SKU mapped first.
                    </p>
                )}

                {more && (
                    <p className="text-muted-foreground text-sm">
                        Only the first 5,000 parcels are shown. Catch these up,
                        then show again for the rest.
                    </p>
                )}

                {result && (
                    <section className="space-y-2 rounded-xl border border-emerald-600/30 bg-emerald-500/10 p-4 text-sm">
                        <p className="font-medium text-emerald-900 dark:text-emerald-100">
                            {result.done} parcel(s) marked packed and
                            dispatched.
                        </p>
                        {result.skipped.length > 0 && (
                            <div className="text-amber-900 dark:text-amber-100">
                                <p className="font-medium">
                                    {result.skipped.length} left as they were:
                                </p>
                                <ul className="list-disc pl-5 text-xs">
                                    {result.skipped.slice(0, 50).map((s) => (
                                        <li key={`${s.id}-${s.reference}`}>
                                            {s.reference}: {s.why}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </section>
                )}

                {days.length === 0 ? (
                    <p className="text-muted-foreground bg-card rounded-xl border px-4 py-10 text-center text-sm">
                        Every parcel with labels in these dates has been scanned
                        or dispatched.
                    </p>
                ) : (
                    <section className="bg-card divide-y overflow-hidden rounded-xl border">
                        {days.map(([day, list]) => {
                            const packable = list.filter(ready);
                            const on = packable.filter((p) =>
                                ticked.has(p.id),
                            ).length;
                            const expanded = open.has(day);
                            const pieces = new Map<string, number>();

                            for (const p of list) {
                                for (const piece of p.pieces) {
                                    pieces.set(
                                        piece.item,
                                        (pieces.get(piece.item) ?? 0) +
                                            piece.units,
                                    );
                                }
                            }

                            return (
                                <div key={day}>
                                    <div className="flex flex-wrap items-center gap-3 px-4 py-3">
                                        <input
                                            type="checkbox"
                                            className="size-4"
                                            aria-label={`Tick every parcel of ${dayLabel(day)}`}
                                            disabled={packable.length === 0}
                                            checked={
                                                packable.length > 0 &&
                                                on === packable.length
                                            }
                                            onChange={(e) =>
                                                toggle(
                                                    packable.map((p) => p.id),
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        <button
                                            type="button"
                                            className="flex min-w-0 flex-1 items-start gap-2 text-left"
                                            onClick={() =>
                                                setOpen((current) => {
                                                    const next = new Set(
                                                        current,
                                                    );

                                                    if (next.has(day)) {
                                                        next.delete(day);
                                                    } else {
                                                        next.add(day);
                                                    }

                                                    return next;
                                                })
                                            }
                                        >
                                            {expanded ? (
                                                <ChevronDown className="mt-0.5 size-4 shrink-0" />
                                            ) : (
                                                <ChevronRight className="mt-0.5 size-4 shrink-0" />
                                            )}
                                            <span className="min-w-0">
                                                <span className="font-medium">
                                                    {dayLabel(day)}
                                                </span>
                                                <span className="text-muted-foreground ml-2 text-sm">
                                                    {list.length} parcel(s) ·{' '}
                                                    {on} ticked
                                                    {list.length -
                                                        packable.length >
                                                        0 &&
                                                        ` · ${list.length - packable.length} cannot be caught up yet`}
                                                </span>
                                                <span className="text-muted-foreground block text-xs">
                                                    {[...pieces.entries()]
                                                        .map(
                                                            ([item, n]) =>
                                                                `${item} × ${n}`,
                                                        )
                                                        .join(' · ')}
                                                </span>
                                            </span>
                                        </button>
                                    </div>
                                    {expanded && (
                                        <ul className="bg-muted/20 divide-y border-t text-sm">
                                            {list.map((p) => (
                                                <li
                                                    key={p.id}
                                                    className="flex flex-wrap items-center gap-3 py-2 pr-4 pl-11"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        className="size-4"
                                                        aria-label={`Tick ${p.awb ?? p.order_number}`}
                                                        disabled={!ready(p)}
                                                        checked={ticked.has(
                                                            p.id,
                                                        )}
                                                        onChange={(e) =>
                                                            toggle(
                                                                [p.id],
                                                                e.target
                                                                    .checked,
                                                            )
                                                        }
                                                    />
                                                    <div className="min-w-0 flex-1">
                                                        <div className="font-mono text-xs">
                                                            {p.awb ??
                                                                p.order_number}
                                                        </div>
                                                        <div className="text-muted-foreground text-xs">
                                                            {[
                                                                p.brand,
                                                                p.marketplace,
                                                                p.courier,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(
                                                                    ' · ',
                                                                )}{' '}
                                                            · {p.contents}
                                                        </div>
                                                    </div>
                                                    <StatusBadge
                                                        variant={
                                                            ready(p)
                                                                ? 'success'
                                                                : p.stock_state ===
                                                                    'short'
                                                                  ? 'destructive'
                                                                  : 'warning'
                                                        }
                                                    >
                                                        {p.stock_label}
                                                    </StatusBadge>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            );
                        })}
                    </section>
                )}

                <div className="bg-card/95 sticky bottom-0 z-20 -mx-4 border-t backdrop-blur sm:-mx-6">
                    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
                        <span className="text-sm">
                            {running
                                ? `Catching up… ${running.done} of ${running.total}`
                                : `${ticked.size} parcel(s) ticked`}
                        </span>
                        <Button
                            disabled={running !== null || ticked.size === 0}
                            onClick={() => void run()}
                        >
                            <PackageCheck className="size-4" />
                            {running
                                ? 'Working…'
                                : `Mark ${ticked.size} as packed and dispatched`}
                        </Button>
                    </div>
                    {running && (
                        <div className="bg-muted h-1">
                            <div
                                className={cn('bg-primary h-1 transition-all')}
                                style={{
                                    width: `${Math.round((running.done / running.total) * 100)}%`,
                                }}
                            />
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

CatchUp.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Online orders', href: onlineOrders() },
        { title: 'Catch up', href: catchUp() },
    ],
};
