import { Head } from '@inertiajs/react';
import { PackageX, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { Scanner } from '@/components/floor/scanner';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { postJson } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as onlineOrders, scanBack } from '@/routes/online-orders';
import { store } from '@/routes/online-orders/scan-back';

type Mode = 'return' | 'cancelled';

type Scan = {
    code: string;
    ok: boolean;
    message: string;
    goods: { item: string; units: string }[];
};

type Reply = {
    message?: string;
    reference?: string;
    order_number?: string | null;
    marketplace?: string | null;
    flag?: string;
    store?: string | null;
    goods?: { item: string; units: string }[];
};

const MODES: Record<
    Mode,
    { title: string; hint: string; icon: typeof Undo2; scanning: string }
> = {
    return: {
        title: 'Return',
        hint: 'Parcels that went out with the courier and came back.',
        icon: Undo2,
        scanning: 'Returns',
    },
    cancelled: {
        title: 'Cancelled order',
        hint: 'Parcels that were never sent: the order was cancelled, or it was left behind.',
        icon: PackageX,
        scanning: 'Cancelled orders',
    },
};

/**
 * The week's end: choose what the pile is, then scan each parcel. Every
 * scan puts its goods back in stock and flags the order.
 */
export default function ScanBack() {
    const [mode, setMode] = useState<Mode | null>(null);
    const [busy, setBusy] = useState(false);
    const [scans, setScans] = useState<Scan[]>([]);

    const onCode = async (code: string) => {
        if (mode === null || busy) {
            return;
        }

        setBusy(true);

        try {
            const { ok, data } = await postJson<Reply>(store().url, {
                code,
                mode,
            });

            setScans((before) => [
                {
                    code,
                    ok,
                    message: ok
                        ? `${data.reference ?? code}${data.order_number ? ` · order ${data.order_number}` : ''}${data.marketplace ? ` · ${data.marketplace}` : ''} — ${data.flag}. Back in ${data.store ?? 'stock'}.`
                        : (data.message ??
                          'This parcel could not be taken back.'),
                    goods: ok ? (data.goods ?? []) : [],
                },
                ...before,
            ]);
        } catch {
            setScans((before) => [
                {
                    code,
                    ok: false,
                    message:
                        'The scan did not reach the server. Check the connection and scan it again.',
                    goods: [],
                },
                ...before,
            ]);
        } finally {
            setBusy(false);
        }
    };

    const done = scans.filter((s) => s.ok).length;
    const failed = scans.length - done;

    return (
        <>
            <Head title="Scan back in" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Scan back in"
                    description="Parcels that are back in hand. Choose what the pile is, then scan each label: its goods go back into stock and the order is flagged."
                />

                {mode === null ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(Object.keys(MODES) as Mode[]).map((m) => {
                            const Icon = MODES[m].icon;

                            return (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => setMode(m)}
                                    className="bg-card hover:border-primary flex flex-col items-start gap-2 rounded-2xl border p-6 text-left transition-colors"
                                >
                                    <Icon className="size-8" />
                                    <span className="text-lg font-semibold">
                                        {MODES[m].title}
                                    </span>
                                    <span className="text-muted-foreground text-sm">
                                        {MODES[m].hint}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <>
                        <div className="bg-card flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3">
                            <p className="text-sm">
                                Scanning:{' '}
                                <span className="font-semibold">
                                    {MODES[mode].scanning}
                                </span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {done} taken back
                                    {failed > 0 ? ` · ${failed} not taken` : ''}
                                </span>
                            </p>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setMode(null);
                                    setScans([]);
                                }}
                            >
                                Change pile
                            </Button>
                        </div>

                        <Scanner
                            onCode={onCode}
                            busy={busy}
                            placeholder="Scan the label, or type its AWB or order number…"
                        />

                        <p className="text-muted-foreground text-xs">
                            Everything in the parcel goes back as good stock. If
                            something in a return is damaged or missing, receive
                            that parcel on the Returns screen instead.
                        </p>

                        <ul className="space-y-2">
                            {scans.map((s, i) => (
                                <li
                                    key={`${s.code}-${scans.length - i}`}
                                    className={cn(
                                        'rounded-xl border px-4 py-3 text-sm',
                                        s.ok
                                            ? 'border-emerald-600/30 bg-emerald-500/5'
                                            : 'border-red-600/30 bg-red-500/5',
                                    )}
                                >
                                    <div className="font-medium">
                                        {s.message}
                                    </div>
                                    {s.goods.length > 0 && (
                                        <div className="text-muted-foreground mt-1 text-xs">
                                            {s.goods
                                                .map(
                                                    (g) =>
                                                        `${g.units} × ${g.item}`,
                                                )
                                                .join(', ')}
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </>
    );
}

ScanBack.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Online orders', href: onlineOrders() },
        { title: 'Scan back in', href: scanBack() },
    ],
};
