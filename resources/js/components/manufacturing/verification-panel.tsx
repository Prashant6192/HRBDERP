import { Link } from '@inertiajs/react';
import { CheckCircle2, ScanLine, XCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import type { Verification } from '@/pages/floor/issue';
import { issue as floorIssue } from '@/routes/floor';

/**
 * What has been scanned against an approved or running order. Every raw
 * material line shows whether a matching, releasable batch was verified at
 * the kettle; blocked scans are listed with the reason.
 */
export function VerificationPanel({
    verification,
    scanCode,
    orderId,
    status,
}: {
    verification: Verification;
    scanCode: string;
    orderId: number;
    status: string;
}) {
    const v = verification;

    return (
        <section className="bg-card rounded-xl border p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">Scan before issue</h2>
                    <p className="text-muted-foreground text-sm">
                        {status === 'approved'
                            ? 'Each raw material is scanned at the kettle before the batch starts; a wrong, expired, rejected or foreign batch is blocked.'
                            : 'What was verified at the kettle for this batch.'}{' '}
                        Order code <span className="font-mono">{scanCode}</span>
                        .
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            v.complete ? 'text-emerald-600' : 'text-amber-600',
                        )}
                    >
                        {v.verified}/{v.required} verified
                    </span>
                    <Button size="sm" variant="outline" asChild>
                        <Link href={floorIssue(orderId)}>
                            <ScanLine className="size-4" />
                            Open on the floor
                        </Link>
                    </Button>
                </div>
            </div>
            <ul className="mt-3 divide-y text-sm">
                {v.lines.map((l) => (
                    <li
                        key={l.item_id}
                        className="flex items-center justify-between gap-3 py-2"
                    >
                        <span>
                            <span className="font-medium">{l.name}</span>
                            <span className="text-muted-foreground block text-xs">
                                {l.code} · {qty(l.planned)} {l.unit ?? ''}
                            </span>
                        </span>
                        {l.verified ? (
                            <span className="flex items-center gap-1 text-xs text-emerald-600">
                                <CheckCircle2 className="size-4" />
                                {l.verified_at
                                    ? new Date(l.verified_at).toLocaleString(
                                          'en-IN',
                                          {
                                              dateStyle: 'short',
                                              timeStyle: 'short',
                                          },
                                      )
                                    : 'verified'}
                            </span>
                        ) : (
                            <span className="text-muted-foreground text-xs">
                                not scanned
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {v.recent.some((s) => s.verdict === 'blocked') && (
                <div className="mt-3 rounded-lg border border-red-600/30 bg-red-500/5 p-3 text-sm">
                    <p className="font-medium text-red-700 dark:text-red-300">
                        Blocked scans
                    </p>
                    <ul className="mt-1 space-y-1">
                        {v.recent
                            .filter((s) => s.verdict === 'blocked')
                            .map((s) => (
                                <li
                                    key={s.id}
                                    className="flex items-start gap-2"
                                >
                                    <XCircle className="mt-0.5 size-4 shrink-0 text-red-600" />
                                    <span>
                                        <span className="font-mono">
                                            {s.code}
                                        </span>
                                        {s.by ? (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {s.by}
                                            </span>
                                        ) : null}
                                        <span className="block text-xs">
                                            {s.reasons.join(' ')}
                                        </span>
                                    </span>
                                </li>
                            ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
