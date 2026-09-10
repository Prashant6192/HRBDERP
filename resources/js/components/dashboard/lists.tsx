import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import { ALERT_LABEL, ALERT_VARIANT, date, qty } from '@/lib/stock';
import { show as showLot } from '@/routes/lots';
import { show as showOrder } from '@/routes/manufacturing';
import { show as showPackaging } from '@/routes/packaging-materials';
import { show as showRawMaterial } from '@/routes/raw-materials';
import type {
    ActivityEntry,
    AttentionRow,
    ExpiringRow,
    InProductionRow,
    UpcomingRow,
} from '@/types';

export function ProductionList({ rows }: { rows: InProductionRow[] }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                Nothing is on the floor right now. Approve a manufacturing order
                to start a batch.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {rows.map((o) => (
                <li key={o.id} className="px-5 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <Link
                                href={showOrder(o.id)}
                                className="font-medium underline-offset-4 hover:underline"
                            >
                                {o.number}
                            </Link>
                            <span className="text-muted-foreground ml-2 text-sm">
                                {o.product ?? o.formula}
                            </span>
                        </div>
                        <span className="text-muted-foreground text-xs tabular-nums">
                            {o.batch}
                        </span>
                    </div>
                    <div className="mt-2 flex items-center gap-2 text-xs">
                        {['Approved', 'In progress', 'Completed'].map(
                            (label, i) => {
                                const step = i + 1;
                                const done = step <= o.stage;
                                const current = step === o.stage;
                                return (
                                    <span
                                        key={label}
                                        className="flex items-center gap-2"
                                    >
                                        <span
                                            className={
                                                done
                                                    ? current
                                                        ? 'bg-primary text-primary-foreground rounded-full px-2 py-0.5 font-medium'
                                                        : 'bg-primary/15 text-primary rounded-full px-2 py-0.5'
                                                    : 'bg-muted text-muted-foreground rounded-full px-2 py-0.5'
                                            }
                                        >
                                            {label}
                                        </span>
                                        {i < 2 && (
                                            <ArrowRight className="text-muted-foreground size-3" />
                                        )}
                                    </span>
                                );
                            },
                        )}
                        <span className="text-muted-foreground ml-auto">
                            {o.started_at
                                ? `since ${date(o.started_at)}`
                                : o.approved_at
                                  ? `approved ${date(o.approved_at)}`
                                  : ''}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}

export function AttentionList({ rows }: { rows: AttentionRow[] }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                Every material in the raw material and packaging stores is above
                its reorder level.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {rows.map((r) => (
                <li
                    key={`${r.store}-${r.item_id}`}
                    className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm"
                >
                    <div className="min-w-0">
                        <Link
                            href={
                                r.type === 'packaging_material'
                                    ? showPackaging(r.item_id)
                                    : showRawMaterial(r.item_id)
                            }
                            className="block truncate font-medium underline-offset-4 hover:underline"
                        >
                            {r.name}
                        </Link>
                        <span className="text-muted-foreground text-xs">
                            {r.code} · {r.store} · {qty(r.on_hand)}{' '}
                            {r.uom ?? ''}
                        </span>
                    </div>
                    <StatusBadge variant={ALERT_VARIANT[r.level]}>
                        {ALERT_LABEL[r.level]}
                    </StatusBadge>
                </li>
            ))}
        </ul>
    );
}

export function ExpiringList({ rows }: { rows: ExpiringRow[] }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                No batch in stock expires soon.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {rows.map((r) => (
                <li
                    key={r.lot_id}
                    className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm"
                >
                    <div className="min-w-0">
                        <Link
                            href={showLot(r.lot_id)}
                            className="block truncate font-medium underline-offset-4 hover:underline"
                        >
                            {r.batch_number}
                        </Link>
                        <span className="text-muted-foreground text-xs">
                            {r.item} · {qty(r.on_hand)} {r.uom ?? ''}
                        </span>
                    </div>
                    <span
                        className={
                            r.days <= 30
                                ? 'text-xs font-medium text-red-700 dark:text-red-300'
                                : 'text-muted-foreground text-xs'
                        }
                    >
                        {r.days <= 0 ? 'expired' : `${r.days} d`} ·{' '}
                        {date(r.expiry_at)}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function UpcomingList({ rows }: { rows: UpcomingRow[] }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                Nothing scheduled in the next two weeks.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {rows.map((r, i) => {
                const d = new Date(r.date);
                return (
                    <li
                        key={i}
                        className="flex items-center gap-3 px-5 py-2.5 text-sm"
                    >
                        <span className="bg-muted flex size-10 shrink-0 flex-col items-center justify-center rounded-lg leading-none">
                            <span className="text-sm font-semibold">
                                {d.getDate()}
                            </span>
                            <span className="text-muted-foreground text-[10px] uppercase">
                                {d.toLocaleDateString('en-IN', {
                                    month: 'short',
                                })}
                            </span>
                        </span>
                        <Link
                            href={r.href}
                            className="min-w-0 flex-1 truncate underline-offset-4 hover:underline"
                        >
                            {r.label}
                        </Link>
                        <StatusBadge
                            variant={r.kind === 'plan' ? 'info' : 'warning'}
                        >
                            {r.kind === 'plan' ? 'Plan' : 'PMR'}
                        </StatusBadge>
                    </li>
                );
            })}
        </ul>
    );
}

export function ActivityList({ rows }: { rows: ActivityEntry[] }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-sm">
                Nothing has been recorded yet.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {rows.map((entry) => (
                <li
                    key={entry.id}
                    className="flex flex-wrap items-center justify-between gap-2 px-5 py-2.5 text-sm"
                >
                    <span className="min-w-0">
                        <span className="font-medium">{entry.actor}</span>{' '}
                        <span className="text-muted-foreground lowercase">
                            {entry.action}
                        </span>
                        {entry.subject && (
                            <>
                                {' — '}
                                <span className="font-medium">
                                    {entry.subject}
                                </span>
                            </>
                        )}
                    </span>
                    {entry.created_at && (
                        <time
                            className="text-muted-foreground text-xs"
                            dateTime={entry.created_at}
                        >
                            {new Date(entry.created_at).toLocaleString(
                                'en-IN',
                                {
                                    day: 'numeric',
                                    month: 'short',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                },
                            )}
                        </time>
                    )}
                </li>
            ))}
        </ul>
    );
}
