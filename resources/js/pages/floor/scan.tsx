import { Head, Link, router } from '@inertiajs/react';
import { Camera, PackageSearch } from 'lucide-react';
import { useState } from 'react';
import { Scanner } from '@/components/floor/scanner';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { QC_LABEL, QC_VARIANT, date, qty } from '@/lib/stock';
import { lookup, photo as photoRoute } from '@/routes/floor';
import type { LotQcStatus } from '@/types';

type Result = {
    kind: 'lot' | 'order' | 'item' | 'location' | 'store' | 'unknown';
    code: string;
    title: string;
    subtitle: string | null;
    actions: { label: string; href: string }[];
    lot?: {
        id: number;
        batch_number: string;
        item: string | null;
        item_code: string | null;
        qc_status: LotQcStatus;
        qc_label: string;
        expiry_at: string | null;
        expired: boolean;
        owner: string | null;
        unit: string | null;
        on_hand: string;
        where: { store: string | null; on_hand: string }[];
    };
    order?: {
        id: number;
        number: string;
        product: string | null;
        status: string;
        status_label: string;
        stage: string | null;
        progress: number;
    };
    item?: {
        id: number;
        code: string;
        name: string;
        type: string;
        unit: string | null;
        on_hand: string;
    };
    location?: { id: number; code: string; name: string; store: string | null };
};

async function resolve(code: string): Promise<Result> {
    const token =
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '';
    const response = await fetch(lookup().url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? token,
            ),
        },
        body: JSON.stringify({ code }),
    });
    if (!response.ok) {
        throw new Error('Lookup failed');
    }
    return (await response.json()) as Result;
}

function PhotoForm({
    subjectType,
    subjectId,
}: {
    subjectType: 'lot' | 'order';
    subjectId: number;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <form
            className="mt-3 space-y-2 rounded-xl border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                if (!file) return;
                setBusy(true);
                router.post(
                    photoRoute().url,
                    {
                        subject_type: subjectType,
                        subject_id: subjectId,
                        photo: file,
                        note,
                    },
                    {
                        forceFormData: true,
                        preserveScroll: true,
                        onFinish: () => {
                            setBusy(false);
                            setFile(null);
                            setNote('');
                        },
                    },
                );
            }}
        >
            <p className="flex items-center gap-2 text-sm font-medium">
                <Camera className="size-4" /> Attach a photo
            </p>
            <Input
                type="file"
                accept="image/*"
                capture="environment"
                onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <Input
                placeholder="Note (optional)"
                value={note}
                onChange={(e) => setNote(e.target.value)}
            />
            <Button
                type="submit"
                size="sm"
                disabled={!file || busy}
                className="w-full"
            >
                Upload
            </Button>
        </form>
    );
}

export default function FloorScan({
    code,
    result,
}: {
    code: string | null;
    result: Result | null;
}) {
    const [current, setCurrent] = useState<Result | null>(result);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const onCode = async (value: string) => {
        setBusy(true);
        setError(null);
        try {
            setCurrent(await resolve(value));
            if (navigator.vibrate) navigator.vibrate(40);
        } catch {
            setError('Could not look that up; try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Head title="Scan" />
            <div className="space-y-4">
                <Scanner
                    onCode={(v) => void onCode(v)}
                    busy={busy}
                    autoStart={!code}
                />
                {error && <p className="text-sm text-red-600">{error}</p>}

                {current && (
                    <section className="bg-card rounded-2xl border p-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="text-muted-foreground text-xs uppercase">
                                    {current.kind}
                                </p>
                                <h2 className="text-lg font-semibold">
                                    {current.title}
                                </h2>
                                {current.subtitle && (
                                    <p className="text-muted-foreground text-sm">
                                        {current.subtitle}
                                    </p>
                                )}
                            </div>
                            {current.kind === 'unknown' && (
                                <PackageSearch className="text-muted-foreground size-6" />
                            )}
                            {current.lot && (
                                <StatusBadge
                                    variant={
                                        current.lot.expired
                                            ? 'destructive'
                                            : QC_VARIANT[current.lot.qc_status]
                                    }
                                >
                                    {current.lot.expired
                                        ? 'Expired'
                                        : QC_LABEL[current.lot.qc_status]}
                                </StatusBadge>
                            )}
                        </div>

                        {current.lot && (
                            <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">
                                    On hand
                                </dt>
                                <dd className="tabular-nums">
                                    {qty(current.lot.on_hand)}{' '}
                                    {current.lot.unit ?? ''}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Expiry
                                </dt>
                                <dd>{date(current.lot.expiry_at)}</dd>
                                <dt className="text-muted-foreground">Owner</dt>
                                <dd>{current.lot.owner ?? 'Ours'}</dd>
                                <dt className="text-muted-foreground">Where</dt>
                                <dd>
                                    {current.lot.where.length === 0
                                        ? 'nowhere'
                                        : current.lot.where
                                              .map(
                                                  (w) =>
                                                      `${w.store} (${qty(w.on_hand)})`,
                                              )
                                              .join(', ')}
                                </dd>
                            </dl>
                        )}

                        {current.order && (
                            <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">
                                    Status
                                </dt>
                                <dd>{current.order.status_label}</dd>
                                <dt className="text-muted-foreground">Stage</dt>
                                <dd>
                                    {current.order.stage
                                        ? `${current.order.stage} · ${current.order.progress}%`
                                        : '—'}
                                </dd>
                            </dl>
                        )}

                        {current.item && (
                            <dl className="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                                <dt className="text-muted-foreground">
                                    On hand everywhere
                                </dt>
                                <dd className="tabular-nums">
                                    {qty(current.item.on_hand)}{' '}
                                    {current.item.unit ?? ''}
                                </dd>
                            </dl>
                        )}

                        {current.actions.length > 0 && (
                            <div className="mt-4 grid gap-2">
                                {current.actions.map((a) => (
                                    <Button
                                        key={a.href + a.label}
                                        asChild
                                        className="h-11 justify-start"
                                    >
                                        <Link href={a.href}>{a.label}</Link>
                                    </Button>
                                ))}
                            </div>
                        )}

                        {current.lot && (
                            <PhotoForm
                                subjectType="lot"
                                subjectId={current.lot.id}
                            />
                        )}
                        {current.order && (
                            <PhotoForm
                                subjectType="order"
                                subjectId={current.order.id}
                            />
                        )}
                    </section>
                )}
            </div>
        </>
    );
}
