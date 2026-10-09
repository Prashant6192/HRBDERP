import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDownToLine,
    ArrowRight,
    Boxes,
    CheckCircle2,
    Clock,
    Hourglass,
    PackageCheck,
    Truck,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { index as dispatchesIndex } from '@/routes/dispatches';
import { index as onlineOrdersIndex } from '@/routes/online-orders';
import { index as receiveIndex } from '@/routes/receive';
import { index as stockIndex } from '@/routes/stock';

export type DepotSummary = {
    date: string;
    online: {
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
            uploaded_by: string | null;
            uploaded_at: string | null;
            closed_at: string | null;
        }[];
        couriers: { courier: string; n: number }[];
    } | null;
    incoming: {
        count: number;
        next: {
            number: string;
            from: string | null;
            dispatched_at: string | null;
        } | null;
    } | null;
    store: { items: number; critical: number; low: number } | null;
    dispatches: number | null;
};

const time = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleTimeString('en-IN', {
              hour: '2-digit',
              minute: '2-digit',
          })
        : '';

const n = (v: number) => v.toLocaleString('en-IN');

const TONE = {
    waiting: 'border-border bg-card',
    uploading:
        'border-sky-300 bg-sky-50 dark:border-sky-800 dark:bg-sky-950/40',
    ready: 'border-orange-300 bg-gradient-to-br from-orange-50 via-amber-50 to-card dark:border-orange-800 dark:from-orange-950/50 dark:via-amber-950/30 dark:to-card',
    done: 'border-emerald-300 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/40',
} as const;

/**
 * The depot's day at a glance: today's online orders first — waiting for
 * the agency, being uploaded, ready to pack, or all done — with one button
 * to start, then the lorry on its way, low finished goods and dispatches.
 */
export function DepotToday({
    facility,
    firstName,
    depot,
}: {
    facility: { id: number; name: string };
    firstName: string;
    depot: DepotSummary;
}) {
    const o = depot.online;
    const agency = o?.uploaded_by.length
        ? o.uploaded_by.join(', ')
        : 'The agency';
    const todays = onlineOrdersIndex({
        query: { facility: facility.id, date: depot.date },
    }).url;

    return (
        <div className="flex flex-col gap-4">
            <p className="text-muted-foreground text-sm">
                Good {greeting()}, {firstName}. Here is {facility.name} today.
            </p>

            {o && (
                <section
                    className={cn(
                        'relative overflow-hidden rounded-2xl border-2 p-5 sm:p-7',
                        TONE[o.state],
                    )}
                    aria-label="Online orders today"
                >
                    {o.state === 'ready' && (
                        <>
                            <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-orange-700 dark:text-orange-300">
                                <span className="relative flex size-2.5">
                                    <span className="absolute inline-flex size-full rounded-full bg-orange-500 opacity-75 motion-safe:animate-ping" />
                                    <span className="relative inline-flex size-2.5 rounded-full bg-orange-500" />
                                </span>
                                Ready to pack
                                {o.finished_at && (
                                    <span className="text-muted-foreground font-normal">
                                        · {agency} finished uploading at{' '}
                                        {time(o.finished_at)}
                                    </span>
                                )}
                            </div>
                            <div className="mt-3 flex flex-wrap items-end justify-between gap-5">
                                <div>
                                    <div className="font-mono text-6xl leading-none font-bold tracking-tight tabular-nums sm:text-7xl">
                                        {n(o.to_pack)}
                                    </div>
                                    <p className="mt-2 text-lg font-semibold">
                                        online order{o.to_pack === 1 ? '' : 's'}{' '}
                                        to pack today
                                    </p>
                                </div>
                                <span className="relative inline-flex">
                                    {/* A soft halo that draws the eye; still for those who ask for less motion. */}
                                    <span
                                        aria-hidden
                                        className="absolute inset-0 rounded-xl bg-orange-500/40 motion-safe:animate-ping motion-safe:[animation-duration:2s]"
                                    />
                                    <Button
                                        asChild
                                        size="lg"
                                        className="relative h-14 rounded-xl px-7 text-base shadow-lg shadow-orange-500/30"
                                    >
                                        <Link href={todays}>
                                            Start processing
                                            <ArrowRight className="size-5" />
                                        </Link>
                                    </Button>
                                </span>
                            </div>
                        </>
                    )}

                    {o.state === 'uploading' && (
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <Hourglass className="mt-1 size-6 shrink-0 text-sky-700 dark:text-sky-300" />
                                <div>
                                    <p className="text-lg font-semibold">
                                        {agency} is uploading today&rsquo;s
                                        labels
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {n(o.total)} label
                                        {o.total === 1 ? '' : 's'} in so far ·{' '}
                                        {o.open_uploads} upload
                                        {o.open_uploads === 1 ? '' : 's'} still
                                        open. You can start on what is in now;
                                        more may follow.
                                    </p>
                                </div>
                            </div>
                            <Button
                                asChild
                                size="lg"
                                className="h-12 rounded-xl"
                            >
                                <Link href={todays}>
                                    Start processing {n(o.to_pack)} now
                                    <ArrowRight className="size-5" />
                                </Link>
                            </Button>
                        </div>
                    )}

                    {o.state === 'waiting' && (
                        <div className="flex items-start gap-3">
                            <Clock className="text-muted-foreground mt-1 size-6 shrink-0" />
                            <div>
                                <p className="text-lg font-semibold">
                                    No labels uploaded yet today
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    When the agency uploads and closes
                                    today&rsquo;s labels, the orders to pack
                                    appear here.
                                </p>
                            </div>
                        </div>
                    )}

                    {o.state === 'done' && (
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="flex items-start gap-3">
                                <CheckCircle2 className="mt-1 size-6 shrink-0 text-emerald-700 dark:text-emerald-300" />
                                <div>
                                    <p className="text-lg font-semibold">
                                        All {n(o.packed)} parcels packed
                                    </p>
                                    <p className="text-muted-foreground text-sm">
                                        {n(o.handed_over)} dispatched ·{' '}
                                        {n(o.packed - o.handed_over)} waiting
                                        for pickup
                                    </p>
                                </div>
                            </div>
                            <Button asChild variant="outline">
                                <Link href={todays}>
                                    See today&rsquo;s orders
                                </Link>
                            </Button>
                        </div>
                    )}

                    {(o.state === 'ready' || o.state === 'done') &&
                        o.total > 0 && (
                            <div className="mt-6 flex flex-col gap-3">
                                <Progress
                                    label="Scanned"
                                    value={o.packed}
                                    total={o.total}
                                    className="bg-orange-500"
                                />
                                <Progress
                                    label="Dispatched"
                                    value={o.handed_over}
                                    total={o.total}
                                    className="bg-emerald-600"
                                />
                            </div>
                        )}

                    {o.batches.length > 0 && (
                        <div className="mt-5 flex flex-wrap gap-2">
                            {o.batches.map((b) => (
                                <span
                                    key={b.id}
                                    className="bg-background/80 inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs"
                                >
                                    <span className="font-semibold">
                                        {b.brand}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {b.marketplace} · {n(b.total)} label
                                        {b.total === 1 ? '' : 's'}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded-full px-1.5 py-0.5 text-[10px] font-semibold',
                                            b.status === 'open'
                                                ? 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'
                                                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                                        )}
                                    >
                                        {b.status === 'open'
                                            ? 'Uploading'
                                            : `Closed ${time(b.closed_at)}`}
                                    </span>
                                </span>
                            ))}
                            {o.couriers.map((c) => (
                                <span
                                    key={c.courier}
                                    className="bg-background/80 text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs"
                                >
                                    <Truck className="size-3" />
                                    {c.courier}{' '}
                                    <b className="text-foreground">{n(c.n)}</b>
                                </span>
                            ))}
                        </div>
                    )}

                    {o.attention > 0 && o.state !== 'waiting' && (
                        <p className="mt-4 flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                            <AlertTriangle className="size-4" />
                            {o.attention} need attention first: short stock, an
                            SKU not mapped, or no AWB read.
                        </p>
                    )}
                </section>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                {depot.incoming && (
                    <Tile
                        icon={ArrowDownToLine}
                        title="Lorry from the factory"
                        value={depot.incoming.count}
                        hint={
                            depot.incoming.next
                                ? `${depot.incoming.next.number} from ${depot.incoming.next.from ?? 'the factory'}`
                                : 'Nothing on the road'
                        }
                        href={
                            receiveIndex({ query: { facility: facility.id } })
                                .url
                        }
                        action="Receive by scan"
                        tone={depot.incoming.count > 0 ? 'info' : 'none'}
                    />
                )}
                {depot.store && (
                    <Tile
                        icon={Boxes}
                        title="Finished goods running low"
                        value={depot.store.critical + depot.store.low}
                        hint={
                            depot.store.critical > 0
                                ? `${depot.store.critical} critical · ${depot.store.low} low of ${depot.store.items}`
                                : `${depot.store.items} products in stock`
                        }
                        href={
                            stockIndex({
                                query: {
                                    store: 'finished_goods',
                                    facility: facility.id,
                                },
                            }).url
                        }
                        action="Open the store"
                        tone={
                            depot.store.critical > 0
                                ? 'bad'
                                : depot.store.low > 0
                                  ? 'warn'
                                  : 'none'
                        }
                    />
                )}
                {depot.dispatches !== null && (
                    <Tile
                        icon={PackageCheck}
                        title="Dispatches to finish"
                        value={depot.dispatches}
                        hint="Waiting for an invoice or to leave"
                        href={
                            dispatchesIndex({
                                query: { facility: facility.id },
                            }).url
                        }
                        action="Open dispatches"
                        tone={depot.dispatches > 0 ? 'info' : 'none'}
                    />
                )}
            </div>
        </div>
    );
}

function greeting(): string {
    const h = new Date().getHours();

    return h < 12 ? 'morning' : h < 17 ? 'afternoon' : 'evening';
}

function Progress({
    label,
    value,
    total,
    className,
}: {
    label: string;
    value: number;
    total: number;
    className: string;
}) {
    const pct = total > 0 ? Math.round((value / total) * 100) : 0;

    return (
        <div>
            <div className="flex justify-between text-sm">
                <span>{label}</span>
                <span className="font-semibold tabular-nums">
                    {n(value)} / {n(total)}
                </span>
            </div>
            <div className="bg-background/80 mt-1 h-2 overflow-hidden rounded-full border">
                <div
                    className={cn(
                        'h-full rounded-full transition-all',
                        className,
                    )}
                    style={{ width: `${pct}%` }}
                />
            </div>
        </div>
    );
}

const TILE_TONE = {
    bad: 'before:bg-red-500',
    warn: 'before:bg-amber-500',
    info: 'before:bg-sky-500',
    none: 'before:bg-border',
} as const;

function Tile({
    icon: Icon,
    title,
    value,
    hint,
    href,
    action,
    tone,
}: {
    icon: typeof Truck;
    title: string;
    value: number;
    hint: string;
    href: string;
    action: string;
    tone: keyof typeof TILE_TONE;
}) {
    return (
        <Link
            href={href}
            className={cn(
                'bg-card hover:bg-muted/40 relative flex flex-col gap-2 overflow-hidden rounded-xl border p-4 pl-5 transition-colors before:absolute before:inset-y-0 before:left-0 before:w-1',
                TILE_TONE[tone],
            )}
        >
            <span className="text-muted-foreground flex items-center gap-2 text-sm">
                <Icon className="size-4" />
                {title}
            </span>
            <span className="text-3xl font-bold tabular-nums">{n(value)}</span>
            <span className="text-muted-foreground text-xs">{hint}</span>
            <span className="text-primary mt-1 inline-flex items-center gap-1 text-sm font-medium">
                {action} <ArrowRight className="size-3.5" />
            </span>
        </Link>
    );
}
