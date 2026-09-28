import { router } from '@inertiajs/react';
import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';

export type Shortfall = {
    item_id: number;
    code: string;
    name: string;
    unit: string | null;
    store: string | null;
    needed: string;
    free: string;
    short: string;
    why: string[];
};

/**
 * What the parcels need that the store cannot cover, and why: where the
 * stock is instead. One button checks again once it has been put right.
 */
export function ShortfallPanel({
    rows,
    checkUrl,
    checkData,
}: {
    rows: Shortfall[];
    checkUrl?: string;
    checkData?: Record<string, string | number | null>;
}) {
    if (rows.length === 0) {
        return null;
    }

    const stores = [...new Set(rows.map((r) => r.store).filter(Boolean))];

    return (
        <section className="rounded-xl border border-red-600/30 bg-red-500/5 p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="flex items-center gap-2 font-semibold">
                        <AlertTriangle className="size-4" />
                        Not enough stock
                        {stores.length === 1 ? ` in ${stores[0]}` : ''}
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Parcels are held as soon as stock lands in the store.
                        Below is where the stock is instead.
                    </p>
                </div>
                {checkUrl && (
                    <Button
                        variant="outline"
                        onClick={() =>
                            router.post(checkUrl, checkData ?? {}, {
                                preserveScroll: true,
                            })
                        }
                    >
                        <RefreshCw className="size-4" />
                        Check stock again
                    </Button>
                )}
            </div>
            <div className="mt-3 divide-y">
                {rows.map((r) => (
                    <div
                        key={`${r.store}-${r.item_id}`}
                        className="grid gap-2 py-3 sm:grid-cols-[1fr_auto]"
                    >
                        <div className="min-w-0">
                            <div className="font-medium">
                                {r.name}{' '}
                                <span className="text-muted-foreground font-mono text-xs">
                                    {r.code}
                                </span>
                            </div>
                            <ul className="text-muted-foreground mt-1 list-disc space-y-0.5 pl-5 text-sm">
                                {r.why.map((w, i) => (
                                    <li key={i}>{w}</li>
                                ))}
                            </ul>
                        </div>
                        <div className="flex gap-4 text-sm tabular-nums sm:text-right">
                            <div>
                                <div className="text-muted-foreground text-xs">
                                    Needed
                                </div>
                                {r.needed} {r.unit}
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs">
                                    Free{r.store ? ` in ${r.store}` : ''}
                                </div>
                                {r.free}
                            </div>
                            <div>
                                <div className="text-muted-foreground text-xs">
                                    Short
                                </div>
                                <span className="font-semibold text-red-700 dark:text-red-300">
                                    {r.short}
                                </span>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </section>
    );
}
