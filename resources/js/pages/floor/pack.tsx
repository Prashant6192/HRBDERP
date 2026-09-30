import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    OctagonX,
    PackageCheck,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { Scanner } from '@/components/floor/scanner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { when } from '@/lib/dispatch';
import {
    courierName,
    describeParcel,
    pieceCount,
    postJson,
    type Parcel,
} from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { pack as packPage } from '@/routes/floor';
import { cancel as cancelRoute, scan as scanRoute } from '@/routes/floor/pack';

type Result = 'scanned' | 'already' | 'dispatched' | 'stop';

type Outcome = {
    result: Result;
    message: string;
    shipment?: Parcel;
    code: string;
    /** What happened after "already scanned": cancelled. */
    settled?: string;
};

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
}: {
    date: string;
    is_today: boolean;
    counts: Counts;
    mine: Parcel[];
    cutoff: string;
    can_cancel: boolean;
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

    const onCode = useCallback(async (code: string) => {
        setBusy(true);

        try {
            const { ok, data } = await postJson<{
                ok: boolean;
                result?: 'scanned' | 'already' | 'dispatched';
                message: string;
                shipment?: Parcel;
            }>(scanRoute().url, { code });

            const result: Result =
                ok && data.ok ? (data.result ?? 'scanned') : 'stop';
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
            <div className="space-y-4">
                <div>
                    <h1 className="text-lg font-semibold">Scan parcels</h1>
                    <p className="text-muted-foreground text-sm">
                        Put the goods in the box, seal it, scan the label. It is
                        packed and on the courier&rsquo;s pile.
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
                            {n.dispatched} dispatched · {n.cancelled} cancelled
                        </p>
                    </div>
                </div>

                <Scanner
                    onCode={onCode}
                    busy={busy}
                    placeholder="Start scanning: scan the label's barcode…"
                />

                {outcome && (
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
                                            outcome.result === 'dispatched') &&
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
                                        {pieceCount(s)} pieces in this parcel.
                                        Put every one in.
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
                                              const picture = s.pictures?.find(
                                                  (p) =>
                                                      p.item_id ===
                                                      pick.item_id,
                                              );
                                              const pieces = Number(pick.units);

                                              return (
                                                  <div
                                                      key={pick.item_id}
                                                      className="bg-background flex items-center gap-3 rounded-xl border p-3"
                                                  >
                                                      {picture && (
                                                          <img
                                                              src={picture.url}
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
                                    · {s.marketplace} · {courierName(s.courier)}{' '}
                                    · {s.payment_label}
                                </p>
                            </div>
                        )}
                    </section>
                )}

                {recent.length > 0 && (
                    <section className="bg-card rounded-2xl border">
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
