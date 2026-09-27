import { Head, Link, router, useForm } from '@inertiajs/react';
import { Camera, CheckCircle2, PackageCheck, ScanLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { BarcodeCamera } from '@/components/barcode-camera';
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
import { dashboard } from '@/routes';
import { book, index, scan } from '@/routes/receive';
import { show as showTransfer } from '@/routes/transfers';
import type { SelectOption } from '@/types';

type Line = {
    id: number;
    item: string | null;
    code: string | null;
    batch: string | null;
    uom: string | null;
    dispatched: string;
    received: string;
    outstanding: string;
    scanned: string;
    cartons_scanned: number;
    cartons_expected: number | null;
};

type Transfer = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    from: string | null;
    store: string | null;
    dispatched_at: string | null;
    vehicle: string | null;
    verified: boolean;
    waiting: number;
    lines: Line[];
};

type LogRow = {
    id: number;
    batch: string | null;
    box: number;
    units: string;
    by: string | null;
    at: string;
    booked: boolean;
};

const time = (iso: string) =>
    new Date(iso).toLocaleTimeString('en-IN', {
        hour: '2-digit',
        minute: '2-digit',
    });

const num = (v: string) => Number(v).toLocaleString('en-IN');

export default function ReceiveByScan({
    facilities,
    facility,
    transfers,
    log,
}: {
    facilities: SelectOption[];
    facility: { id: number; code: string; name: string } | null;
    transfers: Transfer[];
    log: LogRow[];
}) {
    const form = useForm({
        facility_id: facility ? String(facility.id) : '',
        code: '',
        units: '',
    });
    const [camera, setCamera] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const needsUnits = (form.errors.code ?? '').startsWith('How many units');

    useEffect(() => input.current?.focus(), []);

    const submit = (code?: string) => {
        const value = (code ?? form.data.code).trim();

        if (!value) return;

        form.transform((d) => ({
            ...d,
            facility_id: facility ? String(facility.id) : '',
            code: value,
        }));
        form.post(scan().url, {
            preserveScroll: true,
            onSuccess: () => form.reset('code', 'units'),
            // A refused carton is cleared so the next scan starts clean;
            // only a question about its units keeps it for another try.
            onError: (errors) => {
                if (!(errors.code ?? '').startsWith('How many units')) {
                    form.setData('code', '');
                }
            },
            onFinish: () => input.current?.focus(),
        });
    };

    return (
        <>
            <Head title="Receive by scan" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Receive by scan"
                    description={`${facility?.name ?? 'Choose a facility'}. Scan each carton off the lorry. The first scan verifies the consignment, each carton counts once, and the transfer books itself in when the last carton is scanned.`}
                    actions={
                        facilities.length > 1 && (
                            <Select
                                value={facility ? String(facility.id) : ''}
                                onValueChange={(v) =>
                                    router.get(
                                        index({ query: { facility: v } }).url,
                                    )
                                }
                            >
                                <SelectTrigger className="w-60">
                                    <SelectValue placeholder="Facility" />
                                </SelectTrigger>
                                <SelectContent>
                                    {facilities.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={String(f.value)}
                                        >
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )
                    }
                />

                <section className="bg-card rounded-xl border p-4 sm:p-5">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            submit();
                        }}
                        className="flex flex-col gap-3"
                    >
                        <label
                            htmlFor="code"
                            className="flex items-center gap-2 text-sm font-semibold"
                        >
                            <ScanLine className="text-primary size-4" />
                            Scan a carton sticker
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="code"
                                ref={input}
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData('code', e.target.value)
                                }
                                placeholder="Point the scanner at the carton's QR"
                                autoComplete="off"
                                className="h-12 font-mono text-base"
                                disabled={!facility}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                className="h-12"
                                onClick={() => setCamera((c) => !c)}
                                disabled={!facility}
                            >
                                <Camera className="size-4" />
                                <span className="hidden sm:inline">Camera</span>
                            </Button>
                            <Button
                                type="submit"
                                className="h-12 px-5"
                                disabled={form.processing || !facility}
                            >
                                {form.processing ? 'Counting…' : 'Count'}
                            </Button>
                        </div>
                        {needsUnits && (
                            <div className="flex items-center gap-2">
                                <Input
                                    type="number"
                                    min={1}
                                    value={form.data.units}
                                    onChange={(e) =>
                                        form.setData('units', e.target.value)
                                    }
                                    placeholder="Units in this carton"
                                    className="h-11 w-48"
                                    aria-label="Units in this carton"
                                />
                                <span className="text-muted-foreground text-sm">
                                    then scan the carton again, or press Count
                                </span>
                            </div>
                        )}
                        {camera && (
                            <BarcodeCamera
                                onCode={(c) => submit(c)}
                                busy={form.processing}
                                onClose={() => setCamera(false)}
                                className="mx-auto max-w-md"
                            />
                        )}
                        {form.errors.code && (
                            <p
                                role="alert"
                                className="rounded-lg border border-red-600/30 bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-200"
                            >
                                {form.errors.code}
                            </p>
                        )}
                    </form>
                </section>

                {transfers.length === 0 && (
                    <section className="bg-card text-muted-foreground rounded-xl border p-8 text-center text-sm">
                        No lorry is on the road to {facility?.name ?? 'here'}{' '}
                        right now. Transfers appear here as soon as they are
                        dispatched.
                    </section>
                )}

                {transfers.map((t) => {
                    const totalOut = t.lines.reduce(
                        (s, l) => s + Number(l.outstanding),
                        0,
                    );
                    const totalScanned = t.lines.reduce(
                        (s, l) => s + Number(l.scanned),
                        0,
                    );

                    return (
                        <section
                            key={t.id}
                            className="bg-card overflow-hidden rounded-xl border"
                        >
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-3">
                                <Link
                                    href={showTransfer(t.id)}
                                    className="font-mono text-sm font-semibold hover:underline"
                                >
                                    {t.number}
                                </Link>
                                <span className="text-muted-foreground text-sm">
                                    from {t.from} → {t.store}
                                    {t.vehicle ? ` · ${t.vehicle}` : ''}
                                </span>
                                <span
                                    className={cn(
                                        'rounded-full px-2 py-0.5 text-xs font-semibold',
                                        t.verified
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                            : 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
                                    )}
                                >
                                    {t.verified
                                        ? 'Consignment verified'
                                        : t.status_label}
                                </span>
                                <span className="text-muted-foreground ml-auto text-sm tabular-nums">
                                    {num(String(totalScanned))} /{' '}
                                    {num(String(totalOut))} counted
                                </span>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm tabular-nums">
                                    <thead>
                                        <tr className="text-muted-foreground border-b text-left text-[11px] tracking-wide uppercase">
                                            <th className="px-4 py-2 font-semibold">
                                                Product · batch
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                On the lorry
                                            </th>
                                            <th className="px-4 py-2 text-right font-semibold">
                                                Cartons
                                            </th>
                                            <th className="w-48 px-4 py-2 font-semibold">
                                                Counted
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {t.lines.map((l) => {
                                            const out = Number(l.outstanding);
                                            const got = Number(l.scanned);
                                            const pct =
                                                out > 0
                                                    ? Math.min(
                                                          100,
                                                          Math.round(
                                                              (got / out) * 100,
                                                          ),
                                                      )
                                                    : 100;
                                            const done = out > 0 && got >= out;

                                            return (
                                                <tr
                                                    key={l.id}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="px-4 py-2.5">
                                                        <div className="font-medium">
                                                            {l.item}
                                                        </div>
                                                        <div className="text-muted-foreground font-mono text-xs">
                                                            {l.batch ?? '—'}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-2.5 text-right">
                                                        {num(l.outstanding)}{' '}
                                                        {l.uom}
                                                    </td>
                                                    <td className="px-4 py-2.5 text-right">
                                                        {l.cartons_scanned}
                                                        {l.cartons_expected
                                                            ? ` / ${l.cartons_expected}`
                                                            : ''}
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        <div className="flex items-center justify-between text-xs">
                                                            <span
                                                                className={
                                                                    done
                                                                        ? 'font-semibold text-emerald-700 dark:text-emerald-300'
                                                                        : 'text-muted-foreground'
                                                                }
                                                            >
                                                                {done
                                                                    ? 'Complete'
                                                                    : got > 0
                                                                      ? 'Partly'
                                                                      : 'Not yet'}
                                                            </span>
                                                            <span>
                                                                {num(l.scanned)}
                                                            </span>
                                                        </div>
                                                        <div className="bg-muted mt-1 h-1.5 overflow-hidden rounded-full">
                                                            <div
                                                                className={cn(
                                                                    'h-full rounded-full transition-all',
                                                                    done
                                                                        ? 'bg-emerald-600'
                                                                        : 'bg-sky-600',
                                                                )}
                                                                style={{
                                                                    width: `${pct}%`,
                                                                }}
                                                            />
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                            {t.waiting > 0 && (
                                <div className="bg-muted/40 flex flex-wrap items-center gap-3 border-t px-4 py-3">
                                    <p className="text-muted-foreground flex-1 text-sm">
                                        {t.waiting} carton
                                        {t.waiting === 1 ? '' : 's'} counted.
                                        The transfer books itself in at the last
                                        carton. If the lorry is short, book in
                                        what was counted; the rest stays in
                                        transit.
                                    </p>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                book(t.id).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <PackageCheck className="size-4" />
                                        Book in what was counted
                                    </Button>
                                </div>
                            )}
                        </section>
                    );
                })}

                {log.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Latest scans
                        </h2>
                        <ul className="divide-y text-sm">
                            {log.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex items-center gap-3 px-4 py-2"
                                >
                                    {r.booked ? (
                                        <CheckCircle2 className="size-4 text-emerald-600" />
                                    ) : (
                                        <ScanLine className="text-muted-foreground size-4" />
                                    )}
                                    <span className="font-mono">{r.batch}</span>
                                    <span>carton {r.box}</span>
                                    <span className="text-muted-foreground">
                                        {num(r.units)} units
                                    </span>
                                    <span className="text-muted-foreground ml-auto text-xs">
                                        {r.by} · {time(r.at)}
                                        {r.booked ? ' · booked in' : ''}
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

ReceiveByScan.layout = () => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Receive by scan', href: '#' },
    ],
});
