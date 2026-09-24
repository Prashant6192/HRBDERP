import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, OctagonX, PackageCheck, Truck } from 'lucide-react';
import { useCallback, useState } from 'react';
import { Scanner } from '@/components/floor/scanner';
import { Button } from '@/components/ui/button';
import { when } from '@/lib/dispatch';
import { courierName, postJson, type Parcel } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { handover, index as floorIndex } from '@/routes/floor';
import { scan as scanRoute } from '@/routes/floor/pack';

type Outcome = {
    ok: boolean;
    message: string;
    shipment?: Parcel;
    code: string;
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

export default function PackParcels({
    waiting,
    packed_today,
    mine,
    cutoff,
}: {
    waiting: number;
    packed_today: number;
    mine: Parcel[];
    cutoff: string;
}) {
    const [busy, setBusy] = useState(false);
    const [outcome, setOutcome] = useState<Outcome | null>(null);
    const [recent, setRecent] = useState<Parcel[]>(mine);
    const [left, setLeft] = useState(waiting);
    const [done, setDone] = useState(packed_today);

    const onCode = useCallback(async (code: string) => {
        setBusy(true);

        try {
            const { ok, data } = await postJson<{
                ok: boolean;
                message: string;
                shipment?: Parcel;
            }>(scanRoute().url, { code });

            setOutcome({
                ok: ok && data.ok,
                message: data.message,
                shipment: data.shipment,
                code,
            });
            signal(ok && data.ok);

            if (ok && data.ok && data.shipment) {
                const packedParcel = data.shipment;
                setRecent((r) => [packedParcel, ...r].slice(0, 15));
                setLeft((n) => Math.max(0, n - 1));
                setDone((n) => n + 1);
            }
        } catch {
            setOutcome({
                ok: false,
                message: 'No connection. Scan again in a moment.',
                code,
            });
            signal(false);
        } finally {
            setBusy(false);
        }
    }, []);

    const s = outcome?.shipment;

    return (
        <>
            <Head title="Pack parcels" />
            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Pack parcels</h1>
                        <p className="text-muted-foreground text-sm">
                            Put the goods in the box, then scan the barcode on
                            the label.
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={floorIndex()}>Floor</Link>
                    </Button>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="bg-card rounded-2xl border p-3">
                        <p className="text-muted-foreground text-xs uppercase">
                            Waiting
                        </p>
                        <p className="text-3xl font-semibold tabular-nums">
                            {left}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            pack by {cutoff}
                        </p>
                    </div>
                    <div className="bg-card rounded-2xl border p-3">
                        <p className="text-muted-foreground text-xs uppercase">
                            Packed today
                        </p>
                        <p className="text-3xl font-semibold text-emerald-700 tabular-nums dark:text-emerald-300">
                            {done}
                        </p>
                    </div>
                </div>

                <Scanner
                    onCode={onCode}
                    busy={busy}
                    placeholder="Scan the label's barcode…"
                />

                {outcome && (
                    <section
                        role="status"
                        aria-live="assertive"
                        className={cn(
                            'rounded-2xl border-2 p-4',
                            outcome.ok
                                ? 'border-emerald-600 bg-emerald-500/15'
                                : 'border-red-600 bg-red-500/15',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            {outcome.ok ? (
                                <CheckCircle2 className="size-12 shrink-0 text-emerald-600" />
                            ) : (
                                <OctagonX className="size-12 shrink-0 text-red-600" />
                            )}
                            <div>
                                <p
                                    className={cn(
                                        'text-3xl font-bold tracking-wide',
                                        outcome.ok
                                            ? 'text-emerald-700 dark:text-emerald-300'
                                            : 'text-red-700 dark:text-red-300',
                                    )}
                                >
                                    {outcome.ok ? 'PACKED' : 'STOP'}
                                </p>
                                <p className="text-sm">{outcome.message}</p>
                            </div>
                        </div>

                        {s && (
                            <div className="mt-4 space-y-3">
                                <div className="space-y-2">
                                    {s.lines.map((l) => {
                                        const picture = s.pictures?.find(
                                            (p) => p.item_id === l.item_id,
                                        );

                                        return (
                                            <div
                                                key={l.id}
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
                                                        {l.item ?? l.seller_sku}
                                                    </p>
                                                    {l.item && (
                                                        <p className="text-muted-foreground truncate text-xs">
                                                            {l.seller_sku}
                                                        </p>
                                                    )}
                                                </div>
                                                <p className="text-4xl font-bold tabular-nums">
                                                    ×{' '}
                                                    {l.units && l.item
                                                        ? Number(l.units)
                                                        : l.quantity}
                                                </p>
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

                <Button variant="outline" className="h-12 w-full" asChild>
                    <Link href={handover()}>
                        <Truck className="size-4" />
                        Courier pickup
                    </Link>
                </Button>

                {recent.length > 0 && (
                    <section className="bg-card rounded-2xl border">
                        <h2 className="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold">
                            <PackageCheck className="size-4" />
                            You packed today
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
                                        {p.lines
                                            .map(
                                                (l) =>
                                                    `${l.item ?? l.seller_sku} × ${l.quantity}`,
                                            )
                                            .join(', ')}
                                    </span>
                                    <span className="text-muted-foreground text-xs">
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
