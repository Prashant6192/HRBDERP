import { Head, Link, router } from '@inertiajs/react';
import { Download, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import {
    index as onlineOrders,
    report as reportRoute,
} from '@/routes/online-orders';
import { excel } from '@/routes/online-orders/report';

type Row = {
    key: string;
    uploaded: number;
    to_scan: number;
    scanned: number;
    left_behind: number;
    cancelled: number;
    returned: number;
    total: number;
};

type Report = {
    from: string;
    to: string;
    totals: Row;
    days: Row[];
    couriers: Row[];
    brands: Row[];
    products: {
        code: string;
        name: string;
        unit: string | null;
        pieces: string;
        parcels: number;
    }[];
    scanners: {
        name: string;
        scanned: number;
        first: string | null;
        last: string | null;
    }[];
    look_again: {
        date: string;
        awb: string | null;
        order: string | null;
        courier: string;
        brand: string | null;
        what: string;
        reason: string | null;
        by: string | null;
        at: string | null;
        stock_back: boolean;
    }[];
};

const ymd = (d: Date) =>
    `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

const nice = (day: string) =>
    new Date(`${day}T00:00:00`).toLocaleDateString('en-IN', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });

function CountsTable({
    title,
    first,
    rows,
    total,
}: {
    title: string;
    first: string;
    rows: Row[];
    total?: Row;
}) {
    if (rows.length === 0) {
        return null;
    }

    const head = [
        'Uploaded',
        'To scan',
        'Scanned',
        'Left behind',
        'Cancelled',
        'Returned',
    ];
    const cells = (r: Row) => [
        r.uploaded,
        r.to_scan,
        r.scanned,
        r.left_behind,
        r.cancelled,
        r.returned,
    ];

    return (
        <section className="bg-card overflow-hidden rounded-xl border">
            <h2 className="border-b px-4 py-3 text-sm font-semibold">
                {title}
            </h2>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[40rem] text-sm">
                    <thead className="text-muted-foreground text-xs uppercase">
                        <tr className="border-b">
                            <th className="px-4 py-2 text-left font-medium">
                                {first}
                            </th>
                            {head.map((h) => (
                                <th
                                    key={h}
                                    className="px-4 py-2 text-right font-medium"
                                >
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((r) => (
                            <tr key={r.key}>
                                <td className="px-4 py-2 font-medium">
                                    {/^\d{4}-\d{2}-\d{2}$/.test(r.key)
                                        ? nice(r.key)
                                        : r.key}
                                </td>
                                {cells(r).map((v, i) => (
                                    <td
                                        key={i}
                                        className={cn(
                                            'px-4 py-2 text-right tabular-nums',
                                            v === 0 && 'text-muted-foreground',
                                            i === 4 &&
                                                v > 0 &&
                                                'text-red-700 dark:text-red-300',
                                            i === 3 &&
                                                v > 0 &&
                                                'text-amber-700 dark:text-amber-300',
                                        )}
                                    >
                                        {v}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {total && rows.length > 1 && (
                            <tr className="bg-muted/40 font-semibold">
                                <td className="px-4 py-2">Total</td>
                                {cells(total).map((v, i) => (
                                    <td
                                        key={i}
                                        className="px-4 py-2 text-right tabular-nums"
                                    >
                                        {v}
                                    </td>
                                ))}
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function OnlineOrdersReport({
    report,
    facility,
    facilities,
}: {
    report: Report;
    facility: number | null;
    facilities: { value: string; label: string }[];
}) {
    const [from, setFrom] = useState(report.from);
    const [to, setTo] = useState(report.to);
    const query = (f: string, t: string, fac: number | null = facility) =>
        Object.fromEntries(
            Object.entries({ from: f, to: t, facility: fac }).filter(
                ([, v]) => v !== null && v !== '',
            ),
        ) as Record<string, string | number>;
    const go = (f: string, t: string, fac: number | null = facility) =>
        router.get(reportRoute().url, query(f, t, fac), {
            preserveScroll: true,
        });

    const today = new Date();
    const quick: [string, string, string][] = (() => {
        const y = new Date(today);
        y.setDate(y.getDate() - 1);
        const w = new Date(today);
        w.setDate(w.getDate() - 6);
        const m = new Date(today.getFullYear(), today.getMonth(), 1);

        return [
            ['Today', ymd(today), ymd(today)],
            ['Yesterday', ymd(y), ymd(y)],
            ['Last 7 days', ymd(w), ymd(today)],
            ['This month', ymd(m), ymd(today)],
        ];
    })();

    const t = report.totals;
    const tiles: [string, number, string?][] = [
        ['Uploaded', t.uploaded],
        [
            'Scanned · with courier',
            t.scanned,
            'text-emerald-700 dark:text-emerald-300',
        ],
        ['Still to scan', t.to_scan],
        ['Left behind', t.left_behind, 'text-amber-700 dark:text-amber-300'],
        ['Cancelled', t.cancelled, 'text-red-700 dark:text-red-300'],
        ['Returned', t.returned],
    ];

    return (
        <>
            <Head title="Online orders report" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Online orders report"
                    description="Every parcel of the chosen days, read from the scans: by day, courier and brand, the pieces that went out, who scanned, and the parcels to look at again."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={onlineOrders()}>Online orders</Link>
                            </Button>
                            <Button asChild>
                                <a
                                    href={
                                        excel({
                                            query: query(
                                                report.from,
                                                report.to,
                                            ),
                                        }).url
                                    }
                                >
                                    <Download className="size-4" />
                                    Download Excel
                                </a>
                            </Button>
                        </>
                    }
                />

                <div className="bg-card flex flex-wrap items-end gap-3 rounded-xl border p-4">
                    <div className="flex flex-wrap gap-2">
                        {quick.map(([label, f, tt]) => (
                            <Button
                                key={label}
                                size="sm"
                                variant={
                                    report.from === f && report.to === tt
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() => go(f, tt)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                    <div className="flex items-end gap-2">
                        <label className="text-xs">
                            <span className="text-muted-foreground mb-1 block">
                                From
                            </span>
                            <Input
                                type="date"
                                value={from}
                                onChange={(e) => setFrom(e.target.value)}
                                className="h-9"
                            />
                        </label>
                        <label className="text-xs">
                            <span className="text-muted-foreground mb-1 block">
                                To
                            </span>
                            <Input
                                type="date"
                                value={to}
                                onChange={(e) => setTo(e.target.value)}
                                className="h-9"
                            />
                        </label>
                        <Button
                            size="sm"
                            className="h-9"
                            onClick={() => go(from, to)}
                        >
                            Show
                        </Button>
                    </div>
                    {facilities.length > 1 && (
                        <Select
                            value={facility ? String(facility) : 'all'}
                            onValueChange={(v) =>
                                go(
                                    report.from,
                                    report.to,
                                    v === 'all' ? null : Number(v),
                                )
                            }
                        >
                            <SelectTrigger className="h-9 min-w-48">
                                <SelectValue placeholder="All facilities" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All facilities
                                </SelectItem>
                                {facilities.map((f) => (
                                    <SelectItem key={f.value} value={f.value}>
                                        {f.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>

                <p className="text-muted-foreground text-sm">
                    {report.from === report.to
                        ? nice(report.from)
                        : `${nice(report.from)} to ${nice(report.to)}`}
                </p>

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    {tiles.map(([label, value, tone]) => (
                        <div
                            key={label}
                            className="bg-card rounded-xl border p-4"
                        >
                            <p className="text-muted-foreground text-xs uppercase">
                                {label}
                            </p>
                            <p
                                className={cn(
                                    'mt-1 text-3xl font-semibold tabular-nums',
                                    value > 0 && tone,
                                )}
                            >
                                {value}
                            </p>
                        </div>
                    ))}
                </div>

                {t.uploaded === 0 ? (
                    <section className="bg-card text-muted-foreground flex flex-col items-center gap-2 rounded-xl border p-10 text-sm">
                        <FileSpreadsheet className="size-6" />
                        No online orders for these days.
                    </section>
                ) : (
                    <>
                        {report.days.length > 1 && (
                            <CountsTable
                                title="By day"
                                first="Day"
                                rows={report.days}
                                total={report.totals}
                            />
                        )}
                        <CountsTable
                            title="By courier"
                            first="Courier"
                            rows={report.couriers}
                        />
                        <CountsTable
                            title="By brand"
                            first="Brand"
                            rows={report.brands}
                        />

                        <div className="grid gap-6 lg:grid-cols-2">
                            {report.products.length > 0 && (
                                <section className="bg-card overflow-hidden rounded-xl border">
                                    <h2 className="border-b px-4 py-3 text-sm font-semibold">
                                        Pieces that went out
                                    </h2>
                                    <table className="w-full text-sm">
                                        <tbody className="divide-y">
                                            {report.products.map((p) => (
                                                <tr key={p.code}>
                                                    <td className="px-4 py-2">
                                                        {p.name}{' '}
                                                        <span className="text-muted-foreground font-mono text-xs">
                                                            {p.code}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-2 text-right font-semibold tabular-nums">
                                                        {Number(
                                                            p.pieces,
                                                        ).toLocaleString(
                                                            'en-IN',
                                                        )}{' '}
                                                        {p.unit}
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-2 text-right text-xs">
                                                        {p.parcels} parcels
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </section>
                            )}
                            {report.scanners.length > 0 && (
                                <section className="bg-card overflow-hidden rounded-xl border">
                                    <h2 className="border-b px-4 py-3 text-sm font-semibold">
                                        Scanned by
                                    </h2>
                                    <table className="w-full text-sm">
                                        <tbody className="divide-y">
                                            {report.scanners.map((s) => (
                                                <tr key={s.name}>
                                                    <td className="px-4 py-2 font-medium">
                                                        {s.name}
                                                    </td>
                                                    <td className="px-4 py-2 text-right font-semibold tabular-nums">
                                                        {s.scanned}
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-2 text-right text-xs">
                                                        {s.first} – {s.last}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </section>
                            )}
                        </div>

                        {report.look_again.length > 0 && (
                            <section className="bg-card overflow-hidden rounded-xl border">
                                <h2 className="border-b px-4 py-3 text-sm font-semibold">
                                    Cancelled and left behind (
                                    {report.look_again.length})
                                </h2>
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[44rem] text-sm">
                                        <thead className="text-muted-foreground text-xs uppercase">
                                            <tr className="border-b">
                                                {[
                                                    'Day',
                                                    'AWB',
                                                    'Courier',
                                                    'Brand',
                                                    'What',
                                                    'Reason',
                                                    'By',
                                                ].map((h) => (
                                                    <th
                                                        key={h}
                                                        className="px-4 py-2 text-left font-medium"
                                                    >
                                                        {h}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {report.look_again.map((p, i) => (
                                                <tr key={`${p.awb}-${i}`}>
                                                    <td className="px-4 py-2 whitespace-nowrap">
                                                        {nice(p.date)}
                                                    </td>
                                                    <td className="px-4 py-2 font-mono text-xs">
                                                        {p.awb ?? '—'}
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        {p.courier}
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        {p.brand}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'px-4 py-2 font-medium',
                                                            p.what ===
                                                                'Cancelled'
                                                                ? 'text-red-700 dark:text-red-300'
                                                                : 'text-amber-700 dark:text-amber-300',
                                                        )}
                                                    >
                                                        {p.what}
                                                        {p.stock_back && (
                                                            <span className="text-muted-foreground block text-xs font-normal">
                                                                stock put back
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-2 text-xs">
                                                        {p.reason}
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-2 text-xs">
                                                        {p.by}
                                                        {p.at
                                                            ? ` · ${p.at}`
                                                            : ''}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
