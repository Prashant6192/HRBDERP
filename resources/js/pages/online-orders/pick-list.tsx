import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Printer } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as onlineOrders, pickList } from '@/routes/online-orders';
import { index as listingsIndex } from '@/routes/listings';

type Product = {
    item_id: number;
    name: string;
    code: string;
    pieces: number;
    parcels: number;
    short: number;
    on_shelf: number;
    price_low: string | null;
    price_high: string | null;
};

type Store = {
    store: string;
    pieces: number;
    short: number;
    products: Product[];
};

const rupees = (v: string) =>
    `₹${Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`;

function price(p: Product): string {
    if (p.price_low === null || p.price_high === null) {
        return '—';
    }

    return p.price_low === p.price_high
        ? rupees(p.price_low)
        : `${rupees(p.price_low)} – ${rupees(p.price_high)}`;
}

function shift(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, '0'),
        String(d.getDate()).padStart(2, '0'),
    ].join('-');
}

export default function PickList({
    date,
    today,
    stores,
    unmapped_parcels,
    can,
}: {
    date: string;
    today: string;
    stores: Store[];
    unmapped_parcels: number;
    can: { manage: boolean };
}) {
    const go = (d: string) =>
        router.get(pickList({ query: { date: d } }).url, undefined, {
            preserveState: false,
        });

    return (
        <>
            <Head title="Pick list" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Pick list"
                    description="What to take off the racks for the day's labels: each product, how many pieces, and what it is selling for. The stock has already left the system for these; anything short is shown in red."
                    actions={
                        <Button
                            variant="outline"
                            size="sm"
                            className="print:hidden"
                            onClick={() => window.print()}
                        >
                            <Printer className="size-4" />
                            Print
                        </Button>
                    }
                />

                <div className="flex items-center gap-2 print:hidden">
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Previous day"
                        onClick={() => go(shift(date, -1))}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Input
                        type="date"
                        className="w-44"
                        value={date}
                        max={today}
                        onChange={(e) => e.target.value && go(e.target.value)}
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label="Next day"
                        disabled={date >= today}
                        onClick={() => go(shift(date, 1))}
                    >
                        <ChevronRight className="size-4" />
                    </Button>
                    {date !== today && (
                        <Button variant="ghost" onClick={() => go(today)}>
                            Today
                        </Button>
                    )}
                </div>

                {unmapped_parcels > 0 && (
                    <p className="rounded-xl border border-amber-600/30 bg-amber-500/5 px-4 py-3 text-sm">
                        {unmapped_parcels} parcel(s) are not on this list yet:
                        their SKU is not mapped to a product.{' '}
                        {can.manage && (
                            <Link
                                href={listingsIndex()}
                                className="font-medium underline"
                            >
                                Map SKUs
                            </Link>
                        )}
                    </p>
                )}

                {stores.length === 0 && (
                    <p className="text-muted-foreground bg-card rounded-2xl border px-4 py-10 text-center text-sm">
                        No labels uploaded for this day yet.
                    </p>
                )}

                {stores.map((s) => (
                    <section
                        key={s.store}
                        className="bg-card overflow-hidden rounded-2xl border"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                            <h2 className="font-semibold">{s.store}</h2>
                            <p className="text-sm">
                                <span className="font-semibold tabular-nums">
                                    {s.pieces}
                                </span>{' '}
                                piece(s) to take out
                                {s.short > 0 && (
                                    <span className="ml-2 font-semibold text-red-700 dark:text-red-300">
                                        · short by {s.short}
                                    </span>
                                )}
                            </p>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs">
                                        <th className="px-4 py-2 font-medium">
                                            Product
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Take out
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Parcels
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Selling at (per piece)
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Short
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Left in system
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {s.products.map((p) => (
                                        <tr
                                            key={p.item_id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-2">
                                                <div className="font-medium">
                                                    {p.name}
                                                </div>
                                                <div className="text-muted-foreground font-mono text-xs">
                                                    {p.code}
                                                </div>
                                            </td>
                                            <td className="px-4 py-2 text-right text-base font-semibold tabular-nums">
                                                {p.pieces}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2 text-right tabular-nums">
                                                {p.parcels}
                                            </td>
                                            <td className="px-4 py-2 text-right tabular-nums">
                                                {price(p)}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-2 text-right font-semibold tabular-nums',
                                                    p.short > 0
                                                        ? 'text-red-700 dark:text-red-300'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {p.short > 0 ? p.short : '—'}
                                            </td>
                                            <td className="text-muted-foreground px-4 py-2 text-right tabular-nums">
                                                {p.on_shelf}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}

PickList.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Online orders', href: onlineOrders() },
        { title: 'Pick list', href: pickList() },
    ],
};
