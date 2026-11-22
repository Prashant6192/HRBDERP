import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    OctagonX,
    PackageCheck,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Scanner } from '@/components/floor/scanner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { when } from '@/lib/dispatch';
import {
    courierName,
    describeParcel,
    pieceCount,
    postJson,
    type PackCheckRow,
    type Parcel,
} from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { pack as packPage } from '@/routes/floor';
import {
    cancel as cancelRoute,
    open as openRoute,
    product as productRoute,
    scan as scanRoute,
} from '@/routes/floor/pack';

type Result = 'scanned' | 'already' | 'dispatched' | 'stop';

type Outcome = {
    result: Result;
    message: string;
    shipment?: Parcel;
    code: string;
    /** What happened after "already scanned": cancelled. */
    settled?: string;
};

/** A parcel whose label was scanned and whose products are being proved. */
type Open = {
    parcel: Parcel;
    code: string;
    rows: PackCheckRow[];
    /** item id => pieces proved so far. */
    done: Record<number, number>;
    /** item ids passed by eye, not by barcode. */
    eye: number[];
};

/** The last thing the scanner said about the open parcel. */
type Flash = { tone: 'ok' | 'bad' | 'wait'; title: string; text: string };

type Counts = {
    to_scan: number;
    scanned: number;
    dispatched: number;
    cancelled: number;
};

/**
 * A short beep for yes, a low double buzz for no: the packer hears the
 * answer without looking up.
 */
function signal(ok: boolean) {
    try {
        const Ctx =
            window.AudioContext ??
            (window as unknown as { webkitAudioContext?: typeof AudioContext })
                .webkitAudioContext;

        if (Ctx) {
            const ctx = new Ctx();
            const tones = ok
                ? [[880, 0, 0.12]]
                : [
                      [220, 0, 0.18],
                      [220, 0.25, 0.18],
                  ];
            tones.forEach(([freq, start, length]) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.frequency.value = freq;
                osc.connect(gain);
                gain.connect(ctx.destination);
                gain.gain.value = 0.15;
                osc.start(ctx.currentTime + start);
                osc.stop(ctx.currentTime + start + length);
            });
        }
    } catch {
        // No sound: the colour still says it.
    }

    navigator.vibrate?.(ok ? 60 : [120, 80, 120]);
}

export default function ScanParcels({
    date,
    is_today,
    counts,
    mine,
    cutoff,
    can_cancel,
    can_pass_by_eye,
    no_barcode,
}: {
    date: string;
    is_today: boolean;
    counts: Counts;
    mine: Parcel[];
    cutoff: string;
    can_cancel: boolean;
    can_pass_by_eye: boolean;
    /** Products in the day's parcels that have no barcode on file. */
    no_barcode: { id: number; name: string }[];
}) {
    const [busy, setBusy] = useState(false);
    const [outcome, setOutcome] = useState<Outcome | null>(null);
    const [recent, setRecent] = useState<Parcel[]>(mine);
    const [n, setN] = useState<Counts>(counts);

    const goTo = (day: string) =>
        router.get(packPage().url, { date: day }, { preserveScroll: true });
    const shift = (days: number) => {
        const d = new Date(`${date}T00:00:00`);
        d.setDate(d.getDate() + days);
        goTo(
            `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`,
        );
    };

    const [open, setOpen] = useState<Open | null>(null);
    const [flash, setFlash] = useState<Flash | null>(null);
    // What the waiting screen says between parcels: the last one packed,
    // or why a scan was not a label.
    const [note, setNote] = useState<Flash | null>(null);
    // The scanner fires faster than React re-renders: the open parcel is
    // read from here, so two quick scans both count.
    const live = useRef<Open | null>(null);
    const finishing = useRef(false);

    const show = (next: Open | null) => {
        live.current = next;
        setOpen(next);
    };

    /** The label scan that packs: once every piece is proved. */
    const finish = useCallback(async (done: Open) => {
        if (finishing.current) {
            return;
        }

        finishing.current = true;
        setBusy(true);

        try {
            const { ok, data } = await postJson<{
                ok: boolean;
                result?: 'scanned' | 'already' | 'dispatched';
                message: string;
                shipment?: Parcel;
            }>(scanRoute().url, {
                code: done.code,
                by_eye: done.rows
                    .filter((r) => done.eye.includes(r.item_id))
                    .map((r) => r.full_name),
            });

            const result: Result =
                ok && data.ok ? (data.result ?? 'scanned') : 'stop';
            signal(result === 'scanned');

            if (result === 'scanned' && data.shipment) {
                // Packed: straight back to waiting for the next label.
                const parcel = data.shipment;
                setOutcome(null);
                setNote({
                    tone: 'ok',
                    title: 'Packed',
                    text: `${parcel.marketplace ?? 'Order'} ${parcel.order_number ?? parcel.awb ?? done.code} is on the ${courierName(parcel.courier)} pile. Scan the next label.`,
                });
                setRecent((r) => [parcel, ...r].slice(0, 15));
                setN((c) => ({
                    ...c,
                    to_scan: Math.max(0, c.to_scan - 1),
                    scanned: c.scanned + 1,
                }));
            } else {
                setOutcome({
                    result,
                    message: data.message,
                    shipment: data.shipment,
                    code: done.code,
                });
            }
        } catch {
            setOutcome({
                result: 'stop',
                message:
                    'No connection: the parcel is not packed yet. Scan its label again.',
                code: done.code,
            });
            signal(false);
        } finally {
            show(null);
            setFlash(null);
            finishing.current = false;
            setBusy(false);
        }
    }, []);

    /**
     * Count pieces against a row. When nothing is left the parcel waits for
     * Enter (the Packed button): the packer closes the packet, then says so.
     */
    const prove = useCallback(
        (now: Open, row: PackCheckRow, pieces: number, byEye: boolean) => {
            const next: Open = {
                ...now,
                done: {
                    ...now.done,
                    [row.item_id]: Math.min(
                        row.pieces,
                        (now.done[row.item_id] ?? 0) + pieces,
                    ),
                },
                eye:
                    byEye && !now.eye.includes(row.item_id)
                        ? [...now.eye, row.item_id]
                        : now.eye,
            };
            show(next);

            if (
                next.rows.every((r) => (next.done[r.item_id] ?? 0) >= r.pieces)
            ) {
                signal(true);
                setFlash({
                    tone: 'ok',
                    title: 'Everything is in',
                    text: 'Close the packet and press Enter to mark it packed.',
                });

                return;
            }

            signal(true);
            setFlash({
                tone: 'ok',
                title: 'Right product',
                text: `${row.full_name} · ${next.done[row.item_id]} of ${row.pieces}`,
            });
        },
        [],
    );

    /** A scan while a parcel is open is a product going into it. */
    const onProduct = useCallback(
        async (now: Open, code: string) => {
            const scanned = code.trim().toUpperCase();
            const row =
                now.rows.find(
                    (r) =>
                        r.codes[scanned] !== undefined &&
                        (now.done[r.item_id] ?? 0) < r.pieces,
                ) ?? now.rows.find((r) => r.codes[scanned] !== undefined);

            if (row && (now.done[row.item_id] ?? 0) < row.pieces) {
                prove(now, row, row.codes[scanned], false);

                return;
            }

            if (
                row &&
                now.rows.every((r) => (now.done[r.item_id] ?? 0) >= r.pieces)
            ) {
                setFlash({
                    tone: 'ok',
                    title: 'Everything is in',
                    text: 'Nothing more goes in this parcel. Press Enter to mark it packed.',
                });

                return;
            }

            if (row) {
                signal(false);
                setFlash({
                    tone: 'bad',
                    title: 'One too many',
                    text: `${row.full_name} is already in: this parcel takes ${row.pieces}. Take the extra one out.`,
                });

                return;
            }

            // The label itself, scanned again: nothing wrong, nothing to count.
            if (
                [
                    now.code,
                    now.parcel.awb,
                    now.parcel.alt_code,
                    now.parcel.order_number,
                ]
                    .filter(Boolean)
                    .some((c) => String(c).toUpperCase() === scanned)
            ) {
                setFlash({
                    tone: 'wait',
                    title: 'That is the label',
                    text: 'Now scan the barcode on each product.',
                });

                return;
            }

            // A label carries several barcodes, and the camera reads them
            // all: only a barcode that is on another product is a wrong
            // product. Anything else is simply not a product's barcode.
            let other: string | null = null;

            try {
                const response = await fetch(
                    productRoute({ query: { code: scanned } }).url,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );

                if (response.ok) {
                    other = ((await response.json()) as { name: string }).name;
                }
            } catch {
                // Treated as not a product's barcode.
            }

            if (live.current?.code !== now.code) {
                return;
            }

            if (other === null) {
                setFlash({
                    tone: 'wait',
                    title: 'Not a product barcode',
                    text: 'That barcode is not on any product. Scan the barcode printed on the product itself.',
                });

                return;
            }

            signal(false);
            setFlash({
                tone: 'bad',
                title: 'Wrong product',
                text: `You scanned ${other}. Take it out and scan the product shown.`,
            });
        },
        [prove],
    );

    const onCode = useCallback(
        async (code: string) => {
            if (finishing.current) {
                return;
            }

            if (live.current) {
                await onProduct(live.current, code);

                return;
            }

            setBusy(true);

            try {
                const { ok, status, data } = await postJson<{
                    ok: boolean;
                    result?: 'open' | 'scanned' | 'already' | 'dispatched';
                    message: string;
                    shipment?: Parcel;
                }>(openRoute().url, { code });

                // Not a label. If it is a product's barcode (the bottle
                // still in front of the camera after its parcel was
                // packed), say so quietly: it is not a missing parcel.
                if (!ok && status === 404) {
                    const product = await fetch(
                        productRoute({
                            query: { code: code.trim().toUpperCase() },
                        }).url,
                        {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                        },
                    ).catch(() => null);

                    if (product?.ok) {
                        setOutcome(null);
                        setNote({
                            tone: 'wait',
                            title: 'Scan the label first',
                            text: 'That was a product. Scan the label on the packet, then its products.',
                        });

                        return;
                    }
                }

                if (
                    ok &&
                    data.ok &&
                    data.result === 'open' &&
                    data.shipment?.check?.length
                ) {
                    show({
                        parcel: data.shipment,
                        code,
                        rows: data.shipment.check,
                        done: {},
                        eye: [],
                    });
                    setOutcome(null);
                    setFlash(null);
                    setNote(null);
                    signal(true);

                    return;
                }

                setNote(null);

                const result: Result =
                    ok && data.ok && data.result !== 'open'
                        ? (data.result ?? 'scanned')
                        : 'stop';
                setOutcome({
                    result,
                    message: data.message,
                    shipment: data.shipment,
                    code,
                });
                signal(result === 'scanned');

                if (result === 'scanned' && data.shipment) {
                    const parcel = data.shipment;
                    setRecent((r) => [parcel, ...r].slice(0, 15));
                    setN((c) => ({
                        ...c,
                        to_scan: Math.max(0, c.to_scan - 1),
                        scanned: c.scanned + 1,
                    }));
                }
            } catch {
                setOutcome({
                    result: 'stop',
                    message: 'No connection. Scan again in a moment.',
                    code,
                });
                signal(false);
            } finally {
                setBusy(false);
            }
        },
        [onProduct],
    );

    /**
     * Enter passes the products that have no barcode on file: the packer
     * has checked them by eye. A product with a barcode must be scanned.
     */
    const onEnter = useCallback(() => {
        const now = live.current;

        if (!now || finishing.current) {
            return;
        }

        const left = now.rows.filter(
            (r) => (now.done[r.item_id] ?? 0) < r.pieces,
        );

        // Every product scanned: Enter is the Packed button.
        if (left.length === 0) {
            void finish(now);

            return;
        }

        if (left.some((r) => r.has_barcode)) {
            signal(false);
            setFlash({
                tone: 'bad',
                title: 'Not finished',
                text: 'Scan the product shown first. Enter marks the parcel packed once everything is in.',
            });

            return;
        }

        const next: Open = {
            ...now,
            done: Object.fromEntries(
                now.rows.map((r) => [r.item_id, r.pieces]),
            ),
            eye: [...new Set([...now.eye, ...left.map((r) => r.item_id)])],
        };
        show(next);
        void finish(next);
    }, [finish]);

    const putAside = () => {
        show(null);
        setFlash(null);
    };

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && live.current && !finishing.current) {
                putAside();
            }
        };

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const cancelIt = async () => {
        const parcel = outcome?.shipment;

        if (!parcel) {
            return;
        }

        setBusy(true);

        try {
            const { ok, data } = await postJson<{
                ok: boolean;
                message: string;
                shipment?: Parcel;
            }>(cancelRoute(parcel.id).url, {});

            if (ok && data.ok) {
                setOutcome((o) =>
                    o
                        ? {
                              ...o,
                              shipment: data.shipment ?? o.shipment,
                              settled: data.message,
                          }
                        : o,
                );
                setN((c) => ({
                    ...c,
                    scanned: Math.max(0, c.scanned - 1),
                    dispatched:
                        outcome?.result === 'dispatched'
                            ? Math.max(0, c.dispatched - 1)
                            : c.dispatched,
                    cancelled: c.cancelled + 1,
                }));
            } else {
                setOutcome((o) =>
                    o ? { ...o, result: 'stop', message: data.message } : o,
                );
            }
        } finally {
            setBusy(false);
        }
    };

    const s = outcome?.shipment;
    const dayLabel = new Date(`${date}T00:00:00`).toLocaleDateString('en-IN', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    return (
        <>
            <Head title="Scan parcels" />
            {/* On a wide screen the scanner stays on the left and the parcel
                being packed fills the right; on a phone they stack. */}
            <div className="space-y-4 lg:grid lg:grid-cols-[23rem_minmax(0,1fr)] lg:items-start lg:gap-5 lg:space-y-0">
                <div className="space-y-4 lg:col-start-1 lg:row-start-1">
                    <div>
                        <h1 className="text-lg font-semibold">Scan parcels</h1>
                        <p className="text-muted-foreground text-sm">
                            Scan the label: the screen shows what goes in. Scan
                            each product as it goes in. When all are in, press
                            Enter: the parcel is packed and the screen waits for
                            the next label.
                        </p>
                    </div>

                    <div className="bg-card flex items-center gap-2 rounded-2xl border p-2">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Day before"
                            onClick={() => shift(-1)}
                        >
                            <ChevronLeft className="size-5" />
                        </Button>
                        <div className="min-w-0 flex-1 text-center">
                            <p className="text-sm font-semibold">
                                {is_today ? 'Today · ' : ''}
                                {dayLabel}
                            </p>
                            <Input
                                type="date"
                                value={date}
                                onChange={(e) =>
                                    e.target.value && goTo(e.target.value)
                                }
                                className="mx-auto mt-1 h-8 w-40 text-center text-xs"
                                aria-label="Choose the day"
                            />
                        </div>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Day after"
                            onClick={() => shift(1)}
                        >
                            <ChevronRight className="size-5" />
                        </Button>
                    </div>

                    <div className="grid grid-cols-2 gap-3">
                        <div className="bg-card rounded-2xl border p-3">
                            <p className="text-muted-foreground text-xs uppercase">
                                To scan
                            </p>
                            <p className="text-3xl font-semibold tabular-nums">
                                {n.to_scan}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                by {cutoff}
                            </p>
                        </div>
                        <div className="bg-card rounded-2xl border p-3">
                            <p className="text-muted-foreground text-xs uppercase">
                                Scanned
                            </p>
                            <p className="text-3xl font-semibold text-emerald-700 tabular-nums dark:text-emerald-300">
                                {n.scanned}
                            </p>
                            <p className="text-muted-foreground text-xs">
                                {n.dispatched} dispatched · {n.cancelled}{' '}
                                cancelled
                            </p>
                        </div>
                    </div>

                    {no_barcode.length > 0 && !open && (
                        <div className="rounded-2xl border border-red-500/40 bg-red-500/10 p-3 text-sm">
                            <p className="font-semibold text-red-700 dark:text-red-300">
                                {no_barcode.length}{' '}
                                {no_barcode.length === 1
                                    ? 'product to pack has'
                                    : 'products to pack have'}{' '}
                                no barcode on file
                            </p>
                            <p className="mt-0.5">
                                {no_barcode.map((p) => p.name).join(' · ')}
                            </p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                These are checked by eye, not by scan. Ask the
                                office to add the barcode on the product&rsquo;s
                                page.
                            </p>
                        </div>
                    )}

                    <Scanner
                        onCode={onCode}
                        busy={busy}
                        placeholder={
                            open
                                ? 'Scan the barcode on each product…'
                                : "Start scanning: scan the label's barcode…"
                        }
                        repeatMs={1200}
                        captureKeys
                        onEnter={onEnter}
                    />
                </div>

                <div className="space-y-4 lg:sticky lg:top-18 lg:col-start-2 lg:row-span-2 lg:row-start-1">
                    {!open && !outcome && (
                        <div
                            role="status"
                            aria-live="polite"
                            className={cn(
                                'bg-card text-muted-foreground min-h-96 flex-col items-center justify-center gap-3 rounded-2xl border-2 border-dashed p-8 text-center',
                                note ? 'flex' : 'hidden lg:flex',
                                note?.tone === 'ok' &&
                                    'border-solid border-emerald-600 bg-emerald-500/10',
                                note?.tone === 'wait' &&
                                    'border-solid border-amber-500 bg-amber-400/15',
                            )}
                        >
                            {note?.tone === 'ok' ? (
                                <CheckCircle2 className="size-14 text-emerald-600" />
                            ) : (
                                <PackageCheck className="size-14" />
                            )}
                            <p
                                className={cn(
                                    'text-foreground text-3xl font-bold',
                                    note?.tone === 'ok' &&
                                        'text-emerald-700 dark:text-emerald-300',
                                )}
                            >
                                {note?.title ??
                                    'Scan the sticker on the packet'}
                            </p>
                            <p className="text-base">
                                {note?.text ??
                                    'The product to pack will show here.'}
                            </p>
                            {note?.tone === 'ok' && (
                                <p className="text-foreground text-xl font-semibold">
                                    Scan the sticker on the next packet
                                </p>
                            )}
                        </div>
                    )}

                    {open && (
                        <PackStage
                            open={open}
                            flash={flash}
                            busy={busy}
                            canPassByEye={can_pass_by_eye}
                            onPassByEye={(row) =>
                                live.current &&
                                prove(live.current, row, row.pieces, true)
                            }
                            onEnter={onEnter}
                            onPutAside={putAside}
                        />
                    )}

                    {outcome && !open && (
                        <section
                            role="status"
                            aria-live="assertive"
                            className={cn(
                                'rounded-2xl border-2 p-4',
                                outcome.result === 'scanned' &&
                                    'border-emerald-600 bg-emerald-500/15',
                                (outcome.result === 'already' ||
                                    outcome.result === 'dispatched') &&
                                    'border-amber-500 bg-amber-400/20',
                                outcome.result === 'stop' &&
                                    'border-red-600 bg-red-500/15',
                            )}
                        >
                            <div className="flex items-center gap-3">
                                {outcome.result === 'stop' ? (
                                    <OctagonX className="size-12 shrink-0 text-red-600" />
                                ) : outcome.result === 'already' ||
                                  outcome.result === 'dispatched' ? (
                                    <AlertTriangle className="size-12 shrink-0 text-amber-600" />
                                ) : (
                                    <CheckCircle2 className="size-12 shrink-0 text-emerald-600" />
                                )}
                                <div>
                                    <p
                                        className={cn(
                                            'text-3xl font-bold tracking-wide',
                                            outcome.result === 'stop' &&
                                                'text-red-700 dark:text-red-300',
                                            (outcome.result === 'already' ||
                                                outcome.result ===
                                                    'dispatched') &&
                                                'text-amber-800 dark:text-amber-200',
                                            outcome.result === 'scanned' &&
                                                'text-emerald-700 dark:text-emerald-300',
                                        )}
                                    >
                                        {outcome.result === 'stop'
                                            ? 'STOP'
                                            : outcome.result === 'already'
                                              ? 'ALREADY SCANNED'
                                              : outcome.result === 'dispatched'
                                                ? 'ALREADY DISPATCHED'
                                                : 'SCANNED'}
                                    </p>
                                    <p className="text-sm">{outcome.message}</p>
                                </div>
                            </div>

                            {(outcome.result === 'already' ||
                                outcome.result === 'dispatched') &&
                                s && (
                                    <div className="mt-4">
                                        {outcome.settled ? (
                                            <p className="rounded-xl bg-white/60 px-3 py-2 text-sm font-semibold dark:bg-black/20">
                                                {outcome.settled}
                                            </p>
                                        ) : (
                                            can_cancel && (
                                                <Button
                                                    size="lg"
                                                    variant="destructive"
                                                    className="h-14 w-full text-base"
                                                    disabled={busy}
                                                    onClick={cancelIt}
                                                >
                                                    Order cancelled
                                                </Button>
                                            )
                                        )}
                                    </div>
                                )}

                            {s && (
                                <div className="mt-4 space-y-3">
                                    {s.picks.length > 1 && (
                                        <p className="rounded-xl bg-amber-500/20 px-3 py-2 text-base font-semibold text-amber-900 dark:text-amber-100">
                                            {s.picks.length} different products,{' '}
                                            {pieceCount(s)} pieces in this
                                            parcel. Put every one in.
                                        </p>
                                    )}
                                    <div className="space-y-2">
                                        {s.picks.length === 0
                                            ? s.lines.map((l) => (
                                                  <div
                                                      key={l.id}
                                                      className="bg-background rounded-xl border p-3"
                                                  >
                                                      <p className="text-lg font-semibold">
                                                          {l.seller_sku} ×{' '}
                                                          {l.quantity}
                                                      </p>
                                                  </div>
                                              ))
                                            : s.picks.map((pick) => {
                                                  const picture =
                                                      s.pictures?.find(
                                                          (p) =>
                                                              p.item_id ===
                                                              pick.item_id,
                                                      );
                                                  const pieces = Number(
                                                      pick.units,
                                                  );

                                                  return (
                                                      <div
                                                          key={pick.item_id}
                                                          className="bg-background flex items-center gap-3 rounded-xl border p-3"
                                                      >
                                                          {picture && (
                                                              <img
                                                                  src={
                                                                      picture.url
                                                                  }
                                                                  alt=""
                                                                  className="size-16 shrink-0 rounded-lg border object-contain"
                                                              />
                                                          )}
                                                          <div className="min-w-0 flex-1">
                                                              <p className="text-lg leading-tight font-semibold">
                                                                  {pick.item}
                                                              </p>
                                                              <p className="text-muted-foreground truncate text-xs">
                                                                  {pick.skus.join(
                                                                      ', ',
                                                                  )}
                                                              </p>
                                                          </div>
                                                          <div className="text-right">
                                                              <p className="text-muted-foreground text-xs font-semibold uppercase">
                                                                  Pick
                                                              </p>
                                                              <p className="text-4xl leading-none font-bold tabular-nums">
                                                                  {pieces}
                                                              </p>
                                                              <p className="text-muted-foreground text-xs">
                                                                  {pieces === 1
                                                                      ? 'piece'
                                                                      : 'pieces'}
                                                              </p>
                                                          </div>
                                                      </div>
                                                  );
                                              })}
                                    </div>
                                    <p className="text-muted-foreground text-sm">
                                        <span className="font-mono">
                                            {s.awb ?? outcome.code}
                                        </span>{' '}
                                        · {s.marketplace} ·{' '}
                                        {courierName(s.courier)} ·{' '}
                                        {s.payment_label}
                                    </p>
                                </div>
                            )}
                        </section>
                    )}
                </div>

                {recent.length > 0 && (
                    <section className="bg-card rounded-2xl border lg:col-start-1 lg:row-start-2">
                        <h2 className="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold">
                            <PackageCheck className="size-4" />
                            Scanned by you today
                        </h2>
                        <ul className="divide-y text-sm">
                            {recent.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex items-center gap-3 px-4 py-2"
                                >
                                    <span className="font-mono text-xs">
                                        {p.awb}
                                    </span>
                                    <span className="flex-1 truncate">
                                        {describeParcel(p)}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
                                        {courierName(p.courier)} ·{' '}
                                        {when(p.packed_at)}
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

/**
 * The open parcel, large enough to read from across the packing table:
 * where the order came from, what goes in, and how much of it is in.
 */
function PackStage({
    open,
    flash,
    busy,
    canPassByEye,
    onPassByEye,
    onEnter,
    onPutAside,
}: {
    open: Open;
    flash: Flash | null;
    busy: boolean;
    canPassByEye: boolean;
    onPassByEye: (row: PackCheckRow) => void;
    onEnter: () => void;
    onPutAside: () => void;
}) {
    const { parcel, rows, done } = open;
    const left = rows.filter((r) => (done[r.item_id] ?? 0) < r.pieces);
    const next = left[0]?.item_id;
    const onlyByEye = left.length > 0 && left.every((r) => !r.has_barcode);
    const tone = flash?.tone ?? 'wait';
    const circle = 2 * Math.PI * 40;

    return (
        <section
            role="status"
            aria-live="assertive"
            className={cn(
                'bg-card @container overflow-hidden rounded-2xl border-2 shadow-lg',
                tone === 'ok' &&
                    'border-emerald-600 ring-4 ring-emerald-500/20',
                tone === 'bad' && 'border-red-600 ring-4 ring-red-500/25',
                tone === 'wait' && 'border-amber-500',
            )}
        >
            <style>{`@keyframes pack-shake{20%{transform:translateX(-8px)}40%{transform:translateX(8px)}60%{transform:translateX(-5px)}80%{transform:translateX(5px)}}@keyframes pack-rise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}@media (prefers-reduced-motion:reduce){[data-pack-anim]{animation:none!important}}`}</style>

            <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-b px-4 py-3">
                <span className="bg-foreground text-background rounded-xl px-4 py-1.5 text-2xl font-bold @3xl:text-3xl">
                    {parcel.marketplace ?? 'Order'}
                </span>
                <div className="min-w-0">
                    <p className="text-muted-foreground text-xs font-semibold tracking-wider uppercase">
                        Order no.
                    </p>
                    <p className="text-2xl leading-tight font-bold break-all tabular-nums @3xl:text-4xl">
                        {parcel.order_number ?? parcel.awb ?? open.code}
                    </p>
                </div>
                <p className="text-muted-foreground ml-auto text-right text-sm">
                    {courierName(parcel.courier)} · {parcel.payment_label}
                    <br />
                    <span className="font-mono">{parcel.awb}</span>
                </p>
            </div>

            <div
                // A new message replays the shake.
                key={`${flash?.title}${flash?.text}`}
                data-pack-anim
                className={cn(
                    'flex items-center gap-3 px-4 py-3',
                    tone === 'bad' && 'animate-[pack-shake_0.4s]',
                    tone === 'ok' &&
                        'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
                    tone === 'bad' &&
                        'bg-red-500/15 text-red-700 dark:text-red-300',
                    tone === 'wait' &&
                        'bg-amber-400/20 text-amber-800 dark:text-amber-200',
                )}
            >
                {tone === 'bad' ? (
                    <OctagonX className="size-11 shrink-0" />
                ) : tone === 'ok' ? (
                    <CheckCircle2 className="size-11 shrink-0" />
                ) : (
                    <PackageCheck className="size-11 shrink-0" />
                )}
                <div>
                    <p className="text-2xl font-bold tracking-wide uppercase @3xl:text-3xl">
                        {flash?.title ??
                            (rows.length > 1
                                ? `Put these ${rows.length} products in the packet`
                                : 'Put this in the packet')}
                    </p>
                    <p className="text-sm font-medium">
                        {flash?.text ??
                            (onlyByEye
                                ? 'No barcode on file. Check by eye, then press Enter.'
                                : 'Scan each product as it goes in.')}
                    </p>
                </div>
            </div>

            <div className="space-y-3 p-4">
                {rows.map((row, i) => {
                    const got = done[row.item_id] ?? 0;
                    const complete = got >= row.pieces;

                    return (
                        <div
                            key={row.item_id}
                            data-pack-anim
                            style={{ animationDelay: `${i * 70}ms` }}
                            className={cn(
                                'bg-background grid animate-[pack-rise_0.35s_both] grid-cols-[5.5rem_minmax(0,1fr)] items-center gap-4 rounded-2xl border p-3 transition-colors @3xl:grid-cols-[minmax(9rem,16rem)_minmax(0,1fr)_auto] @3xl:gap-6 @3xl:p-4',
                                complete &&
                                    'border-emerald-600 bg-emerald-500/10',
                                !complete &&
                                    row.item_id === next &&
                                    rows.length > 1 &&
                                    'border-primary ring-primary/20 ring-4',
                            )}
                        >
                            <div className="relative">
                                {row.photo ? (
                                    <img
                                        src={row.photo}
                                        alt=""
                                        className="aspect-square w-full rounded-xl bg-white object-contain"
                                    />
                                ) : (
                                    <div className="text-muted-foreground bg-muted flex aspect-square w-full items-center justify-center rounded-xl text-center text-xs">
                                        No photo
                                    </div>
                                )}
                                {row.pieces > 1 && (
                                    <span className="bg-primary text-primary-foreground absolute -top-2 -right-2 rounded-full px-3 py-1 text-xl font-bold shadow">
                                        × {row.pieces}
                                    </span>
                                )}
                            </div>
                            <div className="min-w-0">
                                <p className="text-3xl leading-[1.05] font-bold text-balance break-words @3xl:text-5xl @5xl:text-6xl">
                                    {row.name}
                                </p>
                                {row.size && (
                                    <p className="bg-primary text-primary-foreground mt-2 inline-block rounded-xl px-4 py-1 text-2xl font-bold @3xl:text-4xl">
                                        {row.size}
                                    </p>
                                )}
                            </div>
                            <div className="col-span-2 flex items-center gap-3 @3xl:col-span-1 @3xl:flex-col @3xl:gap-2">
                                <div className="relative size-24 shrink-0">
                                    <svg
                                        viewBox="0 0 96 96"
                                        className="size-24 -rotate-90"
                                    >
                                        <circle
                                            cx="48"
                                            cy="48"
                                            r="40"
                                            fill="none"
                                            strokeWidth="9"
                                            className="stroke-muted"
                                        />
                                        <circle
                                            cx="48"
                                            cy="48"
                                            r="40"
                                            fill="none"
                                            strokeWidth="9"
                                            strokeLinecap="round"
                                            strokeDasharray={circle}
                                            strokeDashoffset={
                                                circle * (1 - got / row.pieces)
                                            }
                                            className={cn(
                                                'transition-[stroke-dashoffset] duration-500',
                                                complete
                                                    ? 'stroke-emerald-600'
                                                    : 'stroke-primary',
                                            )}
                                        />
                                    </svg>
                                    <span
                                        className={cn(
                                            'absolute inset-0 flex items-center justify-center text-2xl font-bold tabular-nums',
                                            complete && 'text-emerald-600',
                                        )}
                                    >
                                        {complete
                                            ? '✓'
                                            : `${got}/${row.pieces}`}
                                    </span>
                                </div>
                                <div className="text-center text-xs font-semibold">
                                    {complete ? (
                                        <span className="text-emerald-700 dark:text-emerald-300">
                                            {open.eye.includes(row.item_id)
                                                ? 'Checked by eye'
                                                : 'Scanned'}
                                        </span>
                                    ) : !row.has_barcode ? (
                                        <span className="text-amber-700 dark:text-amber-300">
                                            No barcode: check by eye, press
                                            Enter
                                        </span>
                                    ) : (
                                        <>
                                            <span className="text-muted-foreground">
                                                Scan this product
                                            </span>
                                            {canPassByEye && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="mt-1 block"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        onPassByEye(row)
                                                    }
                                                >
                                                    Barcode won&rsquo;t scan
                                                </Button>
                                            )}
                                        </>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                {left.length === 0 && (
                    <Button
                        type="button"
                        size="lg"
                        className="h-14 bg-emerald-600 px-8 text-lg text-white hover:bg-emerald-700"
                        disabled={busy}
                        onClick={onEnter}
                    >
                        Packed (Enter)
                    </Button>
                )}
                {onlyByEye && (
                    <Button
                        type="button"
                        size="lg"
                        className="h-12 text-base"
                        disabled={busy}
                        onClick={onEnter}
                    >
                        Checked by eye: packed (Enter)
                    </Button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    disabled={busy}
                    onClick={onPutAside}
                >
                    Put this parcel aside (Esc)
                </Button>
                <p className="text-muted-foreground ml-auto text-xs">
                    Nothing is packed until every product is in and you press
                    Enter.
                </p>
            </div>
        </section>
    );
}
