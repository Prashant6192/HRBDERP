import { Head, router } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import { useState } from 'react';
import { ArtworkGallery } from '@/components/contract/artwork-gallery';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { stage as stageRoute } from '@/routes/manufacturing';
import type { ArtworkRow } from '@/types';

type Order = {
    id: number;
    number: string;
    product: string | null;
    stage: string | null;
    stage_label: string | null;
    progress: number;
    artworks: ArtworkRow[];
};

type Stage = { value: string; label: string; order: number };

export default function FloorProduction({
    orders,
    stages,
    can,
}: {
    orders: Order[];
    stages: Stage[];
    can: { record: boolean };
}) {
    const [selected, setSelected] = useState<number | null>(
        orders[0]?.id ?? null,
    );
    const [stage, setStage] = useState<string>(
        orders[0]?.stage ?? stages[0]?.value ?? 'weighing',
    );
    const [progress, setProgress] = useState<number>(orders[0]?.progress ?? 0);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const order = orders.find((o) => o.id === selected) ?? null;

    const choose = (o: Order) => {
        setSelected(o.id);
        setStage(o.stage ?? stages[0]?.value ?? 'weighing');
        setProgress(o.progress);
    };

    const save = () => {
        if (!order) return;
        setBusy(true);
        router.post(
            stageRoute(order.id).url,
            { stage, progress, note },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setNote('');
                },
            },
        );
    };

    return (
        <>
            <Head title="Record production" />
            <div className="space-y-4">
                <h1 className="flex items-center gap-2 text-lg font-semibold">
                    <Gauge className="text-primary size-5" />
                    Record production
                </h1>

                {orders.length === 0 ? (
                    <p className="text-muted-foreground bg-card rounded-2xl border p-8 text-center text-sm">
                        No batch is in progress.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-2">
                            {orders.map((o) => (
                                <button
                                    key={o.id}
                                    type="button"
                                    onClick={() => choose(o)}
                                    className={cn(
                                        'flex items-center justify-between rounded-2xl border p-3 text-left',
                                        selected === o.id
                                            ? 'border-primary bg-primary/5'
                                            : 'bg-card',
                                    )}
                                >
                                    <span>
                                        <span className="font-medium">
                                            {o.number}
                                        </span>
                                        <span className="text-muted-foreground block text-xs">
                                            {o.product ?? '—'} ·{' '}
                                            {o.stage_label ?? 'not recorded'}{' '}
                                            {o.progress}%
                                        </span>
                                    </span>
                                    <span className="bg-muted h-1.5 w-16 overflow-hidden rounded-full">
                                        <span
                                            className="bg-primary block h-full"
                                            style={{ width: `${o.progress}%` }}
                                        />
                                    </span>
                                </button>
                            ))}
                        </div>

                        {order && (
                            <section className="bg-card space-y-2 rounded-2xl border p-4">
                                <p className="text-sm font-semibold">
                                    {order.number}: how the pack must look
                                </p>
                                <ArtworkGallery
                                    artworks={order.artworks}
                                    compact
                                    className="px-0 py-0"
                                    emptyText="No approved artwork on file for this product. Check with the office before packing."
                                />
                            </section>
                        )}

                        {order && can.record && (
                            <section className="bg-card space-y-4 rounded-2xl border p-4">
                                <p className="text-sm font-semibold">
                                    {order.number}: where is it now?
                                </p>
                                <div className="grid grid-cols-2 gap-2">
                                    {stages.map((s) => (
                                        <button
                                            key={s.value}
                                            type="button"
                                            onClick={() => setStage(s.value)}
                                            className={cn(
                                                'rounded-xl border px-3 py-3 text-sm font-medium',
                                                stage === s.value
                                                    ? 'bg-primary text-primary-foreground border-primary'
                                                    : 'bg-background',
                                            )}
                                        >
                                            {s.order}. {s.label}
                                        </button>
                                    ))}
                                </div>
                                <div>
                                    <div className="flex items-center justify-between text-sm">
                                        <span>How far through this stage</span>
                                        <span className="font-semibold tabular-nums">
                                            {progress}%
                                        </span>
                                    </div>
                                    <input
                                        type="range"
                                        min={0}
                                        max={100}
                                        step={5}
                                        value={progress}
                                        onChange={(e) =>
                                            setProgress(Number(e.target.value))
                                        }
                                        className="accent-primary mt-2 w-full"
                                    />
                                    <div className="mt-2 grid grid-cols-5 gap-1">
                                        {[0, 25, 50, 75, 100].map((p) => (
                                            <Button
                                                key={p}
                                                type="button"
                                                size="sm"
                                                variant={
                                                    progress === p
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                onClick={() => setProgress(p)}
                                            >
                                                {p}%
                                            </Button>
                                        ))}
                                    </div>
                                </div>
                                <Input
                                    placeholder="Note (optional), e.g. pH 5.6"
                                    value={note}
                                    onChange={(e) => setNote(e.target.value)}
                                    className="h-11"
                                />
                                <Button
                                    className="h-12 w-full"
                                    onClick={save}
                                    disabled={busy}
                                >
                                    Save reading
                                </Button>
                            </section>
                        )}
                    </>
                )}
            </div>
        </>
    );
}
