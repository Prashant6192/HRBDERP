import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert, XCircle } from 'lucide-react';
import { useState } from 'react';
import { Scanner } from '@/components/floor/scanner';
import { Button } from '@/components/ui/button';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import {
    scan as scanRoute,
    show as showOrder,
    start,
} from '@/routes/manufacturing';

export type VerificationLine = {
    item_id: number;
    code: string | null;
    name: string | null;
    planned: string;
    unit: string | null;
    verified: boolean;
    verified_at: string | null;
};

export type Verification = {
    lines: VerificationLine[];
    verified: number;
    required: number;
    complete: boolean;
    recent: {
        id: number;
        code: string;
        verdict: 'ok' | 'blocked';
        reasons: string[];
        item: string | null;
        batch: string | null;
        by: string | null;
        at: string;
    }[];
};

export default function FloorIssue({
    order,
    verification,
    packaging,
}: {
    order: {
        id: number;
        number: string;
        product: string | null;
        status: string;
    };
    verification: Verification;
    packaging: Verification;
}) {
    const [busy, setBusy] = useState(false);
    const [flash, setFlash] = useState<{ ok: boolean; text: string } | null>(
        null,
    );

    const onCode = (code: string) => {
        setBusy(true);
        router.post(
            scanRoute(order.id).url,
            { code },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    const toast = (
                        page.props as {
                            flash?: {
                                toast?: { type: string; message: string };
                            };
                        }
                    ).flash?.toast;
                    if (toast)
                        setFlash({
                            ok: toast.type === 'success',
                            text: toast.message,
                        });
                    if (navigator.vibrate)
                        navigator.vibrate(
                            toast?.type === 'success' ? 40 : [80, 60, 80],
                        );
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const Lines = ({ title, v }: { title: string; v: Verification }) =>
        v.lines.length === 0 ? null : (
            <section className="bg-card rounded-2xl border">
                <div className="flex items-center justify-between border-b px-4 py-3">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            v.complete ? 'text-emerald-600' : 'text-amber-600',
                        )}
                    >
                        {v.verified}/{v.required}
                    </span>
                </div>
                <ul className="divide-y">
                    {v.lines.map((l) => (
                        <li
                            key={l.item_id}
                            className="flex items-center justify-between gap-3 px-4 py-3"
                        >
                            <span>
                                <span className="font-medium">{l.name}</span>
                                <span className="text-muted-foreground block text-xs">
                                    {l.code} · {qty(l.planned)} {l.unit ?? ''}
                                </span>
                            </span>
                            {l.verified ? (
                                <CheckCircle2 className="size-6 text-emerald-600" />
                            ) : (
                                <span className="text-muted-foreground text-xs">
                                    not scanned
                                </span>
                            )}
                        </li>
                    ))}
                </ul>
            </section>
        );

    return (
        <>
            <Head title={`Issue · ${order.number}`} />
            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            {order.number}
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {order.product ?? '—'}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={showOrder(order.id)}>Order</Link>
                    </Button>
                </div>

                <p className="text-muted-foreground text-sm">
                    Scan each drum or carton before it goes in. Wrong
                    ingredient, wrong batch, expired stock or another owner's
                    material is blocked and recorded.
                </p>

                <Scanner
                    onCode={onCode}
                    busy={busy}
                    placeholder="Scan the batch sticker…"
                />

                {flash && (
                    <div
                        className={cn(
                            'flex items-start gap-2 rounded-xl border p-3 text-sm',
                            flash.ok
                                ? 'border-emerald-600/30 bg-emerald-500/10'
                                : 'border-red-600/30 bg-red-500/10',
                        )}
                        role="status"
                    >
                        {flash.ok ? (
                            <CheckCircle2 className="size-5 shrink-0 text-emerald-600" />
                        ) : (
                            <ShieldAlert className="size-5 shrink-0 text-red-600" />
                        )}
                        <span>{flash.text}</span>
                    </div>
                )}

                <Lines title="Raw materials" v={verification} />
                <Lines title="Packaging" v={packaging} />

                {order.status === 'approved' && (
                    <Button
                        className="h-12 w-full"
                        disabled={!verification.complete}
                        onClick={() =>
                            router.post(start(order.id).url, undefined, {
                                preserveScroll: true,
                            })
                        }
                    >
                        {verification.complete
                            ? 'Start the batch'
                            : 'Scan every raw material to start'}
                    </Button>
                )}

                {verification.recent.length > 0 && (
                    <section className="bg-card rounded-2xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Recent scans
                        </h2>
                        <ul className="divide-y text-sm">
                            {verification.recent.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex items-start gap-2 px-4 py-2"
                                >
                                    {s.verdict === 'ok' ? (
                                        <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                    ) : (
                                        <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                                    )}
                                    <span className="min-w-0">
                                        <span className="font-medium">
                                            {s.batch ?? s.code}
                                        </span>
                                        {s.item ? (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {s.item}
                                            </span>
                                        ) : null}
                                        {s.verdict === 'blocked' && (
                                            <span className="block text-xs text-red-700 dark:text-red-300">
                                                {s.reasons.join(' ')}
                                            </span>
                                        )}
                                        <span className="text-muted-foreground block text-xs">
                                            {s.by ?? '—'} ·{' '}
                                            {new Date(s.at).toLocaleTimeString(
                                                'en-IN',
                                                {
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                },
                                            )}
                                        </span>
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
