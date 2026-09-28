import { Link } from '@inertiajs/react';
import { PackageOpen, Truck } from 'lucide-react';
import { cn } from '@/lib/utils';

export type DepotOnline = {
    state: 'waiting' | 'uploading' | 'ready' | 'done';
    total: number;
    to_pack: number;
    printed: number;
    packed: number;
    handed_over: number;
    attention: number;
    open_uploads: number;
    uploaded_by: string[];
    finished_at: string | null;
    batches: {
        id: number;
        number: string;
        brand: string | null;
        marketplace: string | null;
        status: string;
        total: number;
        to_pack: number;
    }[];
    couriers: { courier: string; n: number }[];
};

export type DepotSummary = {
    id: number;
    name: string;
    short: string;
    stock: {
        value: string;
        finished_goods_value: string;
        items: number;
        units: string;
        below_reorder: number;
        critical: number;
        low: number;
    };
    online: DepotOnline;
    week: { date: string; shipped: number }[];
    incoming: {
        count: number;
        next: {
            number: string;
            from: string | null;
            dispatched_at: string | null;
        } | null;
    };
    dispatches: {
        pending: number;
        pending_value: string;
        this_month: number;
        this_month_value: string;
    };
    as_of: string;
};

const time = (iso: string) =>
    new Date(iso).toLocaleTimeString('en-IN', {
        hour: 'numeric',
        minute: '2-digit',
    });

/** Today's online orders at a depot, in one card that says where they stand. */
export function OnlineTodayCard({
    online,
    href,
}: {
    online: DepotOnline;
    href?: string;
}) {
    const o = online;
    const agency = o.uploaded_by.join(', ') || 'The agency';

    const headline = {
        waiting: {
            big: '—',
            line: 'No labels uploaded yet today',
            hint: 'The count appears as soon as the agency uploads.',
        },
        uploading: {
            big: String(o.total),
            line: 'online orders so far — agency still uploading',
            hint: `${agency} · ${o.open_uploads} upload${o.open_uploads === 1 ? '' : 's'} still open`,
        },
        ready: {
            big: String(o.to_pack),
            line: 'online orders to pack today',
            hint: o.finished_at
                ? `${agency} finished uploading at ${time(o.finished_at)}`
                : `${agency} has finished uploading`,
        },
        done: {
            big: String(o.packed),
            line: 'online orders packed today — all done',
            hint: `${o.handed_over} of ${o.packed} handed to the couriers`,
        },
    }[o.state];

    const packedPct = o.total > 0 ? Math.round((o.packed / o.total) * 100) : 0;

    const body = (
        <>
            <div className="flex items-center justify-between text-xs">
                <span className="font-medium">Online orders today</span>
                <PackageOpen className="size-4" />
            </div>
            <div className="mt-1 flex items-baseline gap-2">
                <span className="text-3xl font-semibold tabular-nums">
                    {headline.big}
                </span>
                <span className="text-sm">{headline.line}</span>
            </div>
            <p className="mt-0.5 text-xs opacity-80">{headline.hint}</p>

            {o.total > 0 && (
                <div className="mt-3">
                    <div className="flex justify-between text-xs opacity-80">
                        <span>
                            Packed {o.packed} of {o.total}
                        </span>
                        <span>{o.handed_over} with couriers</span>
                    </div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-black/10 dark:bg-white/15">
                        <div
                            className="h-full rounded-full bg-current"
                            style={{ width: `${packedPct}%` }}
                        />
                    </div>
                </div>
            )}

            {o.couriers.length > 0 && o.state !== 'done' && (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {o.couriers.map((c) => (
                        <span
                            key={c.courier}
                            className="rounded-full bg-black/5 px-2 py-0.5 text-[11px] dark:bg-white/10"
                        >
                            {c.courier} · {c.n}
                        </span>
                    ))}
                </div>
            )}

            {o.attention > 0 && (
                <p className="mt-2 text-xs font-medium text-red-700 dark:text-red-300">
                    {o.attention} parcel{o.attention === 1 ? '' : 's'} need a
                    look — short stock, unmapped SKU or no AWB.
                </p>
            )}
        </>
    );

    const className = cn(
        'block rounded-2xl border p-4',
        o.state === 'ready' &&
            'border-orange-500/60 bg-orange-500/10 text-orange-950 dark:text-orange-100',
        o.state === 'uploading' && 'border-sky-500/50 bg-sky-500/5',
        o.state === 'done' && 'border-emerald-500/50 bg-emerald-500/5',
        o.state === 'waiting' && 'bg-card text-muted-foreground',
        href && 'active:opacity-80',
    );

    return href ? (
        <Link href={href} className={className}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

/** Parcels handed to couriers over the last seven days, as small bars. */
export function WeekBars({
    week,
}: {
    week: { date: string; shipped: number }[];
}) {
    const max = Math.max(1, ...week.map((d) => d.shipped));

    return (
        <div className="flex h-16 items-end gap-1.5">
            {week.map((d) => (
                <div
                    key={d.date}
                    className="flex flex-1 flex-col items-center gap-1"
                    title={`${d.date}: ${d.shipped}`}
                >
                    <span className="text-muted-foreground text-[10px] tabular-nums">
                        {d.shipped > 0 ? d.shipped : ''}
                    </span>
                    <div
                        className="bg-primary/70 w-full rounded-sm"
                        style={{
                            height: `${Math.max(2, (d.shipped / max) * 36)}px`,
                        }}
                    />
                    <span className="text-muted-foreground text-[10px]">
                        {new Date(`${d.date}T00:00:00`).toLocaleDateString(
                            'en-IN',
                            { weekday: 'narrow' },
                        )}
                    </span>
                </div>
            ))}
        </div>
    );
}

export function LorryLine({
    incoming,
}: {
    incoming: DepotSummary['incoming'];
}) {
    if (incoming.count === 0) {
        return null;
    }

    return (
        <p className="text-muted-foreground mt-3 flex items-center gap-1.5 text-xs">
            <Truck className="size-3.5 shrink-0" />
            {incoming.count} lorr{incoming.count === 1 ? 'y' : 'ies'} on the way
            {incoming.next?.from ? ` from ${incoming.next.from}` : ''}
            {incoming.next ? ` · ${incoming.next.number}` : ''}
        </p>
    );
}
