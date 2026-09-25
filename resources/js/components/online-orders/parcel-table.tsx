import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { StatusBadge } from '@/components/status-badge';
import { TONE_VARIANT, rupees, when } from '@/lib/dispatch';
import { courierName, needsAttention, type Parcel } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { show as showBatch } from '@/routes/online-orders';

/** Parcels grouped by courier, in the order the couriers first appear. */
export function byCourier(parcels: Parcel[]): [string, Parcel[]][] {
    const map = new Map<string, Parcel[]>();
    parcels.forEach((p) => {
        const key = courierName(p.courier);
        map.set(key, [...(map.get(key) ?? []), p]);
    });

    return Array.from(map.entries());
}

function Contents({ p }: { p: Parcel }) {
    const unmapped = p.lines.filter((l) => !l.mapped);

    if (p.lines.length === 0) {
        return (
            <span className="text-red-700 dark:text-red-300">
                Product not on label
            </span>
        );
    }

    return (
        <div className="space-y-0.5">
            {p.picks.map((pick) => (
                <div key={pick.item_id} className="flex items-baseline gap-2">
                    <span className="font-medium">{pick.item}</span>
                    <span
                        className={cn(
                            'rounded px-1.5 text-xs font-semibold tabular-nums',
                            Number(pick.units) > 1
                                ? 'bg-amber-500/15 text-amber-800 dark:text-amber-200'
                                : 'bg-muted',
                        )}
                    >
                        ×{Number(pick.units)}
                    </span>
                </div>
            ))}
            {unmapped.map((l) => (
                <div key={l.id} className="text-red-700 dark:text-red-300">
                    {l.seller_sku} × {l.quantity}
                    <span className="text-xs"> · not mapped yet</span>
                </div>
            ))}
            <div className="text-muted-foreground truncate text-xs">
                {p.picks.length > 1 && (
                    <span className="mr-1 font-semibold text-sky-700 dark:text-sky-300">
                        Combo · {p.picks.length} products ·
                    </span>
                )}
                {p.lines
                    .map((l) => `${l.seller_sku} × ${l.quantity}`)
                    .join(', ')}
            </div>
            {p.warnings.length > 0 &&
                (p.status === 'uploaded' || p.status === 'printed') && (
                    <div className="text-xs text-amber-700 dark:text-amber-300">
                        {p.warnings.join(' ')}
                    </div>
                )}
        </div>
    );
}

function Progress({ p }: { p: Parcel }) {
    if (p.status === 'returned') {
        return <>Came back {when(p.returned_at)}</>;
    }

    if (p.packed_at) {
        return (
            <>
                Packed {when(p.packed_at)}
                <br />
                by {p.packed_by ?? '—'}
                {p.pack_method === 'manual' && (
                    <span className="block text-amber-700 dark:text-amber-300">
                        without a scan: {p.pack_note}
                    </span>
                )}
            </>
        );
    }

    if (p.cancel_reason) {
        return <>Cancelled: {p.cancel_reason}</>;
    }

    if (p.print_count > 0) {
        return (
            <>
                Printed {when(p.printed_at)}
                {p.print_count > 1 ? ` · ${p.print_count} times` : ''}
            </>
        );
    }

    return <>Not printed</>;
}

/**
 * One table for every courier's parcels, so the columns line up from one
 * courier to the next; each courier opens with its own header row.
 */
export function ParcelTable({
    parcels,
    actions,
    showBatch: withBatch = false,
    empty = 'Nothing here.',
}: {
    parcels: Parcel[];
    actions?: (p: Parcel) => ReactNode;
    showBatch?: boolean;
    empty?: string;
}) {
    const groups = byCourier(parcels);
    const columns = 5 + (actions ? 1 : 0);

    if (groups.length === 0) {
        return (
            <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                {empty}
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[52rem] table-fixed text-sm">
                <colgroup>
                    <col className="w-[13.5rem]" />
                    <col />
                    <col className="w-[7rem]" />
                    <col className="w-[10rem]" />
                    <col className="w-[11rem]" />
                    {actions && <col className="w-[10.5rem]" />}
                </colgroup>
                <thead className="text-muted-foreground text-left text-xs uppercase">
                    <tr className="border-b">
                        <th className="px-4 py-2 font-medium">Parcel</th>
                        <th className="px-4 py-2 font-medium">What goes in</th>
                        <th className="px-4 py-2 font-medium">Payment</th>
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">Progress</th>
                        {actions && <th className="px-4 py-2" />}
                    </tr>
                </thead>
                {groups.map(([courier, list]) => (
                    <tbody key={courier} className="divide-y border-b">
                        <tr className="bg-muted/50">
                            <td
                                colSpan={columns}
                                className="px-4 py-2 text-xs font-semibold tracking-wide uppercase"
                            >
                                <span>{courier}</span>
                                <span className="text-muted-foreground ml-2 font-normal normal-case">
                                    {list.length} parcel
                                    {list.length === 1 ? '' : 's'}
                                </span>
                            </td>
                        </tr>
                        {list.map((p) => (
                            <tr
                                key={p.id}
                                className={cn(
                                    'align-top',
                                    needsAttention(p) && 'bg-red-500/5',
                                    (p.status === 'cancelled' ||
                                        p.status === 'returned') &&
                                        'opacity-70',
                                )}
                            >
                                <td className="px-4 py-3">
                                    <div className="truncate font-mono font-medium">
                                        {p.awb ?? (
                                            <span className="text-red-700 dark:text-red-300">
                                                No AWB
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-muted-foreground truncate font-mono text-xs">
                                        {p.order_number ?? '—'}
                                    </div>
                                    <div className="text-muted-foreground truncate text-xs">
                                        {withBatch ? (
                                            <Link
                                                href={showBatch(p.batch_id)}
                                                className="underline-offset-4 hover:underline"
                                            >
                                                {p.brand} · {p.marketplace}
                                            </Link>
                                        ) : (
                                            <>page {p.pages.join(', ')}</>
                                        )}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <Contents p={p} />
                                </td>
                                <td className="px-4 py-3">
                                    <div>{p.payment_label}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {p.payable_amount
                                            ? rupees(p.payable_amount)
                                            : ''}
                                    </div>
                                </td>
                                <td className="px-4 py-3">
                                    <div className="flex flex-col items-start gap-1">
                                        <StatusBadge
                                            variant={
                                                TONE_VARIANT[p.status_tone]
                                            }
                                        >
                                            {p.status_label}
                                        </StatusBadge>
                                        {p.stock_label &&
                                            p.stock_tone &&
                                            p.status !== 'cancelled' && (
                                                <StatusBadge
                                                    variant={
                                                        TONE_VARIANT[
                                                            p.stock_tone
                                                        ]
                                                    }
                                                >
                                                    {p.stock_label}
                                                </StatusBadge>
                                            )}
                                    </div>
                                </td>
                                <td className="text-muted-foreground px-4 py-3 text-xs">
                                    <Progress p={p} />
                                </td>
                                {actions && (
                                    <td className="px-4 py-3">
                                        <div className="flex justify-end gap-1">
                                            {actions(p)}
                                        </div>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                ))}
            </table>
        </div>
    );
}
