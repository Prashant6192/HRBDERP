import { Link } from '@inertiajs/react';
import { Sparkles } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import {
    OUTLOOK_LABEL,
    OUTLOOK_VARIANT,
    type ItemOutlook,
} from '@/lib/intelligence';
import { cn } from '@/lib/utils';
import { reorderAdvice } from '@/routes/purchase';

/**
 * Where a material is heading: what is happening now, what is likely to go
 * wrong next, and what to do about it — in sentences, with the figures
 * behind them one line each.
 */
export function OutlookPanel({
    outlook,
    canPurchase,
}: {
    outlook: ItemOutlook;
    canPurchase: boolean;
}) {
    const last = outlook.sentences.length - 1;

    return (
        <section className="bg-card rounded-xl border p-6">
            <div className="mb-4 flex items-start justify-between gap-3">
                <div>
                    <h2 className="inline-flex items-center gap-2 font-semibold">
                        <Sparkles className="text-primary size-4" />
                        Outlook
                    </h2>
                    <p className="text-muted-foreground text-xs">
                        Read from the ledger, plans and batches just now.
                    </p>
                </div>
                <StatusBadge variant={OUTLOOK_VARIANT[outlook.status]}>
                    {OUTLOOK_LABEL[outlook.status]}
                </StatusBadge>
            </div>

            <ul className="space-y-1.5 text-sm">
                {outlook.sentences.map((line, i) => (
                    <li
                        key={i}
                        className={cn(
                            'flex items-start gap-2',
                            i === last && 'mt-2 border-t pt-3 font-medium',
                        )}
                    >
                        <span
                            className={cn(
                                'mt-2 size-1.5 shrink-0 rounded-full',
                                i === last
                                    ? 'bg-primary'
                                    : 'bg-muted-foreground/50',
                            )}
                        />
                        <span>{line}</span>
                    </li>
                ))}
            </ul>

            {canPurchase && outlook.status !== 'ok' && (
                <p className="mt-4 text-xs">
                    <Link
                        href={reorderAdvice()}
                        className="text-primary underline-offset-4 hover:underline"
                    >
                        See every material that needs ordering →
                    </Link>
                </p>
            )}
        </section>
    );
}
