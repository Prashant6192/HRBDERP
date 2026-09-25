import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileText,
    PackageCheck,
    Printer,
    Search,
    Truck,
    Undo2,
    Upload,
    X,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { CancelOrderDialog } from '@/components/online-orders/cancel-order-dialog';
import { ParcelTable } from '@/components/online-orders/parcel-table';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { TONE_VARIANT, when } from '@/lib/dispatch';
import {
    canCancel,
    courierName,
    describeParcel,
    type Abilities,
    type Batch,
    type Parcel,
} from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { pdf as sheetPdf } from '@/routes/handover-sheets';
import { create, index, show } from '@/routes/online-orders';
import {
    create as receiveReturn,
    index as returnsIndex,
} from '@/routes/online-orders/returns';

type Counts = {
    total: number;
    not_printed: number;
    printed: number;
    packed: number;
    handed_over: number;
    cancelled: number;
    returned: number;
    attention: number;
};

type Show =
    | 'all'
    | 'attention'
    | 'not_printed'
    | 'printed'
    | 'to_pack'
    | 'packed'
    | 'handed_over'
    | 'cancelled'
    | 'returned';

const SHOW_LABEL: Record<Show, string> = {
    all: 'All parcels',
    attention: 'Need attention',
    not_printed: 'Not printed',
    printed: 'Printed, not packed',
    to_pack: 'To pack',
    packed: 'Packed, waiting for the courier',
    handed_over: 'With the courier',
    cancelled: 'Cancelled',
    returned: 'Returned',
};

type CourierRow = {
    courier: string | null;
    total: number;
    not_printed: number;
    printed: number;
    packed: number;
    handed_over: number;
};

type Sheet = {
    id: number;
    number: string;
    courier: string;
    count: number;
    store: string | null;
    by: string | null;
    received_by: string | null;
    at: string;
};

function shiftDay(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0'),
    ].join('-');
}

function Tile({
    label,
    value,
    tone = 'default',
    hint,
    active,
    onClick,
}: {
    label: string;
    value: number;
    tone?: 'default' | 'danger' | 'warning' | 'success';
    hint?: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'bg-card hover:border-primary/60 rounded-xl border p-4 text-left transition',
                tone === 'danger' && 'border-red-600/40 bg-red-500/5',
                tone === 'warning' && 'border-amber-600/40 bg-amber-500/5',
                active && 'ring-primary ring-2 ring-offset-2',
            )}
        >
            <p className="text-muted-foreground text-xs tracking-wide uppercase">
                {label}
            </p>
            <p
                className={cn(
                    'mt-1 text-3xl font-semibold tabular-nums',
                    tone === 'danger' && 'text-red-700 dark:text-red-300',
                    tone === 'warning' && 'text-amber-700 dark:text-amber-300',
                    tone === 'success' &&
                        'text-emerald-700 dark:text-emerald-300',
                )}
            >
                {value}
            </p>
            {hint && (
                <p className="text-muted-foreground mt-1 text-xs">{hint}</p>
            )}
        </button>
    );
}

/**
 * A courier's light: glowing green once every parcel for it is packed,
 * amber while some wait, red once it is past the cut-off with some still
 * waiting.
 */
function CourierLight({ state }: { state: 'done' | 'waiting' | 'late' }) {
    const colour =
        state === 'done'
            ? 'bg-emerald-500'
            : state === 'late'
              ? 'bg-red-500'
              : 'bg-amber-400';

    return (
        <span
            className="relative flex size-3.5 shrink-0"
            title={
                state === 'done'
                    ? 'Every parcel for this courier is packed'
                    : state === 'late'
                      ? 'Past the cut-off with parcels not packed'
                      : 'Parcels still to pack'
            }
        >
            {state !== 'waiting' && (
                <span
                    className={cn(
                        'absolute inline-flex size-full animate-ping rounded-full opacity-75',
                        colour,
                    )}
                />
            )}
            <span
                className={cn(
                    'relative inline-flex size-3.5 rounded-full',
                    colour,
                    state === 'done' &&
                        'shadow-[0_0_10px_3px_rgba(16,185,129,0.65)]',
                    state === 'late' &&
                        'shadow-[0_0_10px_3px_rgba(239,68,68,0.6)]',
                )}
            />
        </span>
    );
}

export default function OnlineOrdersIndex({
    date,
    is_today,
    cutoff,
    past_cutoff,
    batches,
    totals,
    couriers,
    parcels,
    show: showing,
    courier: courierFilter,
    sheets,
    search,
    found,
    facility,
    facilities,
    can,
}: {
    date: string;
    is_today: boolean;
    cutoff: string;
    past_cutoff: boolean;
    batches: (Batch & { counts: Counts })[];
    totals: Omit<Counts, 'total'> & { parcels: number };
    couriers: CourierRow[];
    parcels: Parcel[];
    show: Show;
    courier: string | null;
    sheets: Sheet[];
    search: string;
    found: Parcel[];
    facility: number | null;
    facilities: { value: string; label: string }[];
    can: Abilities & { return: boolean };
}) {
    const [query, setQuery] = useState(search);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [cancelling, setCancelling] = useState<Parcel | null>(null);
    const go = (params: Record<string, string | number | null>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({
                    date,
                    facility,
                    q: search || null,
                    show: showing === 'all' ? null : showing,
                    courier: courierFilter,
                    ...params,
                }).filter(
                    ([k, v]) => v !== null && (v !== '' || k === 'courier'),
                ),
            ),
            { preserveState: true, preserveScroll: true },
        );
    const pick = (next: Show, courier: string | null = null) =>
        go({ show: next === 'all' ? null : next, courier });
    const mayCancelAny = can.upload || can.print || can.pack || can.manage;

    const onSearch = (e: FormEvent) => {
        e.preventDefault();
        go({ q: query.trim() || null });
    };

    const awaiting = totals.not_printed + totals.printed;
    const dayLabel = new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return (
        <>
            <Head title="Online orders" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Online orders"
                    description="The marketplaces' labels for the day: uploaded by the agency, printed by courier, packed by scanning each label, handed to the courier."
                    actions={
                        <>
                            {mayCancelAny && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setCancelling(null);
                                        setCancelOpen(true);
                                    }}
                                >
                                    <Ban className="size-4" />
                                    Cancel an order
                                </Button>
                            )}
                            {(can.return || !can.restricted) && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline">
                                            <Undo2 className="size-4" />
                                            Returns
                                            <ChevronDown className="size-4 opacity-60" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        {can.return && (
                                            <DropdownMenuItem asChild>
                                                <Link href={receiveReturn()}>
                                                    Receive a return
                                                </Link>
                                            </DropdownMenuItem>
                                        )}
                                        {!can.restricted && (
                                            <DropdownMenuItem asChild>
                                                <Link href={returnsIndex()}>
                                                    All returns and claims
                                                </Link>
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {can.upload && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Upload className="size-4" />
                                        Upload labels
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Previous day"
                        onClick={() => go({ date: shiftDay(date, -1) })}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Input
                        type="date"
                        value={date}
                        onChange={(e) => go({ date: e.target.value })}
                        className="w-44"
                        aria-label="Day"
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Next day"
                        onClick={() => go({ date: shiftDay(date, 1) })}
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                    <span className="text-muted-foreground text-sm">
                        {is_today ? 'Today, ' : ''}
                        {dayLabel}
                    </span>
                    {facilities.length > 1 && (
                        <select
                            className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                            value={facility ?? ''}
                            onChange={(e) =>
                                go({ facility: e.target.value || null })
                            }
                            aria-label="Facility"
                        >
                            <option value="">All facilities</option>
                            {facilities.map((f) => (
                                <option key={f.value} value={f.value}>
                                    {f.label}
                                </option>
                            ))}
                        </select>
                    )}
                    <form
                        onSubmit={onSearch}
                        className="ml-auto flex min-w-64 items-center gap-2"
                    >
                        <Input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="AWB, order, invoice, customer, SKU…"
                            aria-label="Find a parcel"
                        />
                        <Button type="submit" variant="outline" size="icon">
                            <Search className="size-4" />
                        </Button>
                    </form>
                </div>

                {past_cutoff && (
                    <div
                        className="flex items-start gap-3 rounded-xl border border-red-600/30 bg-red-500/10 p-4 text-sm"
                        role="alert"
                    >
                        <AlertTriangle className="size-5 shrink-0 text-red-600" />
                        <div>
                            <p className="font-medium">
                                {awaiting} parcel(s) not packed and it is past{' '}
                                {cutoff}.
                            </p>
                            <p className="text-muted-foreground">
                                Every printed label must be packed or cancelled
                                with a reason. Open the batch to see which ones.
                            </p>
                        </div>
                    </div>
                )}

                {search !== '' && (
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Parcels matching “{search}”
                        </h2>
                        {found.length === 0 ? (
                            <p className="text-muted-foreground px-4 py-6 text-sm">
                                Nothing matches.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {found.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                                    >
                                        <Link
                                            href={show(p.batch_id)}
                                            className="font-mono font-medium underline-offset-4 hover:underline"
                                        >
                                            {p.awb ??
                                                p.order_number ??
                                                `#${p.id}`}
                                        </Link>
                                        <span className="text-muted-foreground">
                                            {p.marketplace} · {p.brand} ·{' '}
                                            {courierName(p.courier)}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {describeParcel(p)}
                                        </span>
                                        <StatusBadge
                                            variant={
                                                TONE_VARIANT[p.status_tone]
                                            }
                                            className="ml-auto"
                                        >
                                            {p.status_label}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Tile
                        label="Parcels"
                        value={totals.parcels}
                        active={showing === 'all' && courierFilter === null}
                        onClick={() => pick('all')}
                    />
                    <Tile
                        label="Not printed"
                        value={totals.not_printed}
                        tone={totals.not_printed > 0 ? 'warning' : 'default'}
                        active={showing === 'not_printed'}
                        onClick={() => pick('not_printed')}
                    />
                    <Tile
                        label="Printed, not packed"
                        value={totals.printed}
                        tone={
                            totals.printed > 0
                                ? past_cutoff
                                    ? 'danger'
                                    : 'warning'
                                : 'default'
                        }
                        hint={`Pack by ${cutoff}`}
                        active={showing === 'printed'}
                        onClick={() => pick('printed')}
                    />
                    <Tile
                        label="Packed"
                        value={totals.packed}
                        tone={totals.packed > 0 ? 'success' : 'default'}
                        hint="Waiting for the courier"
                        active={showing === 'packed'}
                        onClick={() => pick('packed')}
                    />
                    <Tile
                        label="With courier"
                        value={totals.handed_over}
                        tone={totals.handed_over > 0 ? 'success' : 'default'}
                        active={showing === 'handed_over'}
                        onClick={() => pick('handed_over')}
                    />
                    <Tile
                        label="Need attention"
                        value={totals.attention}
                        tone={totals.attention > 0 ? 'danger' : 'default'}
                        hint="No product, no stock or no AWB"
                        active={showing === 'attention'}
                        onClick={() => pick('attention')}
                    />
                </div>

                {couriers.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <Truck className="size-4" /> By courier
                            </h2>
                            <span className="text-muted-foreground text-xs">
                                Green: every parcel packed · click to list
                            </span>
                        </div>
                        <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                            {couriers.map((c) => {
                                const waiting = c.not_printed + c.printed;
                                const state =
                                    waiting === 0
                                        ? 'done'
                                        : past_cutoff
                                          ? 'late'
                                          : 'waiting';
                                const key = c.courier ?? '';
                                const selected = courierFilter === key;

                                return (
                                    <div
                                        key={key || '—'}
                                        className={cn(
                                            'rounded-lg border p-3 transition',
                                            state === 'done' &&
                                                'border-emerald-500/60 bg-emerald-500/5',
                                            selected &&
                                                'ring-primary ring-2 ring-offset-2',
                                        )}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => pick('all', key)}
                                            className="flex w-full items-center gap-2 text-left"
                                        >
                                            <CourierLight state={state} />
                                            <span className="min-w-0 flex-1 truncate font-medium">
                                                {courierName(c.courier)}
                                            </span>
                                            <span className="text-2xl font-semibold tabular-nums">
                                                {c.total}
                                            </span>
                                        </button>
                                        <p
                                            className={cn(
                                                'mt-1 text-xs font-medium',
                                                state === 'done'
                                                    ? 'text-emerald-700 dark:text-emerald-300'
                                                    : state === 'late'
                                                      ? 'text-red-700 dark:text-red-300'
                                                      : 'text-amber-700 dark:text-amber-300',
                                            )}
                                        >
                                            {state === 'done'
                                                ? 'All packed'
                                                : `${waiting} still to pack`}
                                        </p>
                                        <div className="mt-2 grid grid-cols-3 gap-1 text-xs">
                                            {(
                                                [
                                                    [
                                                        'not_printed',
                                                        c.not_printed,
                                                        'to print',
                                                        Printer,
                                                    ],
                                                    [
                                                        'printed',
                                                        c.printed,
                                                        'to pack',
                                                        PackageCheck,
                                                    ],
                                                    [
                                                        'packed',
                                                        c.packed,
                                                        'to hand over',
                                                        Truck,
                                                    ],
                                                ] as const
                                            ).map(([show, n, label, Icon]) => (
                                                <button
                                                    key={show}
                                                    type="button"
                                                    onClick={() =>
                                                        pick(show, key)
                                                    }
                                                    className={cn(
                                                        'hover:bg-muted flex flex-col items-start rounded-md px-1.5 py-1 text-left',
                                                        selected &&
                                                            showing === show &&
                                                            'bg-muted',
                                                    )}
                                                >
                                                    <span className="flex items-center gap-1 text-sm font-semibold tabular-nums">
                                                        <Icon className="text-muted-foreground size-3.5" />
                                                        {n}
                                                    </span>
                                                    <span className="text-muted-foreground leading-tight">
                                                        {label}
                                                    </span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}

                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                        <h2 className="mr-auto text-sm font-semibold">
                            {SHOW_LABEL[showing]}
                            {courierFilter !== null &&
                                ` · ${courierName(courierFilter || null)}`}
                            <span className="text-muted-foreground ml-2 font-normal">
                                {parcels.length}
                            </span>
                        </h2>
                        {(
                            [
                                'all',
                                'to_pack',
                                'packed',
                                'cancelled',
                                'returned',
                            ] as Show[]
                        ).map((key) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => pick(key, courierFilter)}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-xs',
                                    showing === key
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-muted',
                                )}
                            >
                                {key === 'all' ? 'All' : SHOW_LABEL[key]}
                                {key === 'cancelled' &&
                                    ` (${totals.cancelled})`}
                                {key === 'returned' && ` (${totals.returned})`}
                            </button>
                        ))}
                        {(showing !== 'all' || courierFilter !== null) && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => pick('all', null)}
                            >
                                <X className="size-4" />
                                Clear
                            </Button>
                        )}
                    </div>
                    <ParcelTable
                        parcels={parcels}
                        showBatch
                        empty="No parcels here for this day."
                        actions={(p) => (
                            <>
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={show(p.batch_id)}>Open</Link>
                                </Button>
                                {canCancel(can, p) && (
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Cancel this order"
                                        aria-label="Cancel this order"
                                        onClick={() => {
                                            setCancelling(p);
                                            setCancelOpen(true);
                                        }}
                                    >
                                        <Ban className="size-4" />
                                    </Button>
                                )}
                            </>
                        )}
                    />
                </section>

                <section className="bg-card rounded-xl border">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Uploads for the day
                        </h2>
                        <span className="text-muted-foreground text-xs">
                            One per brand, marketplace and store
                        </span>
                    </div>
                    {batches.length === 0 ? (
                        <div className="text-muted-foreground px-4 py-10 text-center text-sm">
                            <FileText className="mx-auto mb-2 size-6" />
                            No labels uploaded for this day
                            {can.upload ? '. Upload the first file.' : ' yet.'}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left text-xs uppercase">
                                    <tr className="border-b">
                                        <th className="px-4 py-2 font-medium">
                                            Batch
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Brand · marketplace
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Ships from
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Parcels
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Not printed
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            To pack
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Packed
                                        </th>
                                        <th className="px-4 py-2 font-medium">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {batches.map((b) => (
                                        <tr
                                            key={b.id}
                                            className="hover:bg-muted/40 cursor-pointer"
                                            onClick={() =>
                                                router.visit(show(b.id).url)
                                            }
                                        >
                                            <td className="px-4 py-3 font-mono font-medium whitespace-nowrap">
                                                <Link href={show(b.id)}>
                                                    {b.number}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium">
                                                    {b.brand}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {b.marketplace} · by{' '}
                                                    {b.uploaded_by ?? '—'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div>{b.facility}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {b.store}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {b.counts.total}
                                                {b.counts.attention > 0 && (
                                                    <span className="ml-2 inline-flex items-center gap-1 text-xs text-red-700 dark:text-red-300">
                                                        <AlertTriangle className="size-3" />
                                                        {b.counts.attention}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {b.counts.not_printed}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-3 text-right tabular-nums',
                                                    past_cutoff &&
                                                        b.counts.printed > 0 &&
                                                        'font-semibold text-red-700 dark:text-red-300',
                                                )}
                                            >
                                                {b.counts.printed}
                                            </td>
                                            <td className="px-4 py-3 text-right tabular-nums">
                                                {b.counts.packed +
                                                    b.counts.handed_over}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    variant={
                                                        b.status === 'open'
                                                            ? 'info'
                                                            : 'muted'
                                                    }
                                                >
                                                    {b.status_label}
                                                </StatusBadge>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>

                {sheets.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Courier handovers
                        </h2>
                        <ul className="divide-y text-sm">
                            {sheets.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    <a
                                        href={sheetPdf(s.id).url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-mono font-medium underline-offset-4 hover:underline"
                                    >
                                        {s.number}
                                    </a>
                                    <span>
                                        {s.count} to {s.courier}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {s.store} · {s.by ?? '—'}
                                        {s.received_by
                                            ? ` → ${s.received_by}`
                                            : ''}
                                    </span>
                                    <span className="text-muted-foreground ml-auto text-xs">
                                        {when(s.at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <CancelOrderDialog
                open={cancelOpen}
                onOpenChange={(o) => {
                    setCancelOpen(o);

                    if (!o) {
                        setCancelling(null);
                    }
                }}
                parcel={cancelling}
            />
        </>
    );
}
