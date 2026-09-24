import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    FileText,
    PackageCheck,
    Printer,
    Search,
    Truck,
    Upload,
} from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TONE_VARIANT, when } from '@/lib/dispatch';
import {
    courierName,
    type Abilities,
    type Batch,
    type Parcel,
} from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { pdf as sheetPdf } from '@/routes/handover-sheets';
import { create, index, show } from '@/routes/online-orders';

type Counts = {
    total: number;
    not_printed: number;
    printed: number;
    packed: number;
    handed_over: number;
    cancelled: number;
    attention: number;
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
}: {
    label: string;
    value: number;
    tone?: 'default' | 'danger' | 'warning' | 'success';
    hint?: string;
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
        </div>
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
    sheets: Sheet[];
    search: string;
    found: Parcel[];
    facility: number | null;
    facilities: { value: string; label: string }[];
    can: Abilities;
}) {
    const [query, setQuery] = useState(search);
    const go = (params: Record<string, string | number | null>) =>
        router.get(
            index().url,
            Object.fromEntries(
                Object.entries({
                    date,
                    facility,
                    q: search || null,
                    ...params,
                }).filter(([, v]) => v !== null && v !== ''),
            ),
            { preserveState: true, preserveScroll: true },
        );

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
            <div className="space-y-6">
                <PageHeader
                    title="Online orders"
                    description="The marketplaces' labels for the day: uploaded by the agency, printed by courier, packed by scanning each label, handed to the courier."
                    actions={
                        can.upload && (
                            <Button asChild>
                                <Link href={create()}>
                                    <Upload className="size-4" />
                                    Upload labels
                                </Link>
                            </Button>
                        )
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
                                            {p.lines
                                                .map(
                                                    (l) =>
                                                        `${l.item ?? l.seller_sku} × ${l.quantity}`,
                                                )
                                                .join(', ')}
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
                    <Tile label="Parcels" value={totals.parcels} />
                    <Tile
                        label="Not printed"
                        value={totals.not_printed}
                        tone={totals.not_printed > 0 ? 'warning' : 'default'}
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
                    />
                    <Tile
                        label="Packed"
                        value={totals.packed}
                        tone={totals.packed > 0 ? 'success' : 'default'}
                    />
                    <Tile
                        label="With courier"
                        value={totals.handed_over}
                        tone={totals.handed_over > 0 ? 'success' : 'default'}
                    />
                    <Tile
                        label="Need attention"
                        value={totals.attention}
                        tone={totals.attention > 0 ? 'danger' : 'default'}
                        hint="No product, no stock or no AWB"
                    />
                </div>

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

                {couriers.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-4 py-3">
                            <h2 className="flex items-center gap-2 text-sm font-semibold">
                                <Truck className="size-4" /> By courier
                            </h2>
                            <span className="text-muted-foreground text-xs">
                                What each pickup should take
                            </span>
                        </div>
                        <div className="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                            {couriers.map((c) => (
                                <div
                                    key={c.courier ?? '—'}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex items-baseline justify-between">
                                        <span className="font-medium">
                                            {courierName(c.courier)}
                                        </span>
                                        <span className="text-2xl font-semibold tabular-nums">
                                            {c.total}
                                        </span>
                                    </div>
                                    <div className="text-muted-foreground mt-2 grid grid-cols-3 gap-1 text-xs">
                                        <span className="flex items-center gap-1">
                                            <Printer className="size-3" />
                                            {c.not_printed} to print
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <PackageCheck className="size-3" />
                                            {c.printed} to pack
                                        </span>
                                        <span className="flex items-center gap-1">
                                            <Truck className="size-3" />
                                            {c.packed} to hand over
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                )}

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
        </>
    );
}
