import { Head, Link, router } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    ClipboardCheck,
    Clock,
    Factory,
    PackageSearch,
    RefreshCw,
    Signature,
    Truck,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { FacilityFilter } from '@/components/intelligence/facility-filter';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import type { FacilityChip } from '@/lib/intelligence';
import { date } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { commandCentre, dashboard } from '@/routes';

type Severity = 'high' | 'medium' | 'low';

type Exception = {
    key: string;
    rule: string;
    rule_label: string;
    subject: string;
    severity: Severity;
    title: string;
    detail: string;
    href: string | null;
    since: string;
    age_hours: number;
};

type Running = {
    id: number;
    number: string;
    product: string | null;
    client: string | null;
    facility: string | null;
    batch: string;
    status: string;
    status_label: string;
    started_at: string | null;
    elapsed_hours: number | null;
    late: boolean;
    required_delivery_at: string | null;
    href: string;
};

type AwaitingQc = {
    id: number;
    number: string;
    item: string | null;
    batch: string | null;
    client: string | null;
    quantity: string;
    status: string;
    waiting_hours: number;
    slow: boolean;
    href: string;
};

type Short = {
    item_id: number;
    code: string;
    name: string;
    unit: string | null;
    shortage: string;
    requested: string;
    client_material: boolean;
    plans: {
        id: number;
        number: string;
        start: string | null;
        client: string | null;
        shortage: string;
    }[];
    requests: { id: number; number: string; needed_by: string | null }[];
};

type Due = {
    id: number;
    number: string;
    product: string | null;
    client: string | null;
    client_po_ref: string | null;
    required_delivery_at: string | null;
    overdue: boolean;
    status: string;
    status_label: string;
    ready: boolean;
    href: string;
};

type AwaitingDispatch = {
    lot_id: number;
    batch_number: string;
    client: string | null;
    product: string | null;
    on_hand: string;
    manufactured_at: string | null;
    href: string;
};

type ApprovalRow = {
    kind: string;
    label: string;
    number: string;
    who: string | null;
    since: string | null;
    href: string | null;
};

type Tomorrow = {
    kind: 'material' | 'batch';
    title: string;
    detail: string;
    href: string | null;
    severity: Severity;
};

type Snapshot = {
    as_of: string;
    running: Running[];
    delayed: Exception[];
    awaiting_qc: AwaitingQc[];
    short: Short[];
    dispatching: {
        due: Due[];
        awaiting_dispatch: AwaitingDispatch[];
        online: {
            parcels: number;
            to_pack: number;
            packed: number;
            handed_over: number;
            past_cutoff: boolean;
            cutoff: string;
            href: string;
        } | null;
    };
    approvals: ApprovalRow[];
    tomorrow: Tomorrow[];
    exceptions: Exception[];
    counts: {
        exceptions_high: number;
        exceptions: number;
        order_today: number;
    };
};

const SEVERITY: Record<Severity, 'destructive' | 'warning' | 'info'> = {
    high: 'destructive',
    medium: 'warning',
    low: 'info',
};

function hours(h: number | null): string {
    if (h === null) return '—';
    if (h < 1) return `${Math.round(h * 60)} min`;
    if (h < 48) return `${h} h`;
    return `${Math.round((h / 24) * 10) / 10} d`;
}

function Panel({
    icon: Icon,
    title,
    count,
    tone = 'default',
    children,
    className,
}: {
    icon: typeof Factory;
    title: string;
    count: number;
    tone?: 'default' | 'warning' | 'danger' | 'success';
    children: ReactNode;
    className?: string;
}) {
    const tones = {
        default: 'bg-primary/10 text-primary',
        warning: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        danger: 'bg-red-500/15 text-red-700 dark:text-red-300',
        success: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    };

    return (
        <section
            className={cn(
                'bg-card flex flex-col rounded-2xl border',
                className,
            )}
        >
            <div className="flex items-center justify-between gap-3 border-b px-5 py-3">
                <h2 className="flex items-center gap-2 font-semibold">
                    <span
                        className={cn(
                            'flex size-8 items-center justify-center rounded-lg',
                            tones[tone],
                        )}
                    >
                        <Icon className="size-4" />
                    </span>
                    {title}
                </h2>
                <span className="text-2xl font-semibold tabular-nums">
                    {count}
                </span>
            </div>
            <div className="flex-1 p-4">{children}</div>
        </section>
    );
}

function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="text-muted-foreground py-6 text-center text-sm">
            {children}
        </p>
    );
}

function Row({
    href,
    title,
    meta,
    right,
    tone,
}: {
    href: string | null;
    title: ReactNode;
    meta?: ReactNode;
    right?: ReactNode;
    tone?: 'late' | 'ok';
}) {
    const inner = (
        <div
            className={cn(
                'flex items-start justify-between gap-3 rounded-lg px-2 py-2 text-sm',
                href && 'hover:bg-muted/50',
                tone === 'late' && 'bg-red-500/5',
            )}
        >
            <div className="min-w-0">
                <div className="truncate font-medium">{title}</div>
                {meta && (
                    <div className="text-muted-foreground truncate text-xs">
                        {meta}
                    </div>
                )}
            </div>
            {right && <div className="shrink-0 text-right">{right}</div>}
        </div>
    );

    return href ? <Link href={href}>{inner}</Link> : inner;
}

export default function CommandCentre({
    snapshot,
    filters,
    facilities,
    timezone,
}: {
    snapshot: Snapshot;
    filters: { facility: number | null };
    facilities: FacilityChip[];
    timezone: string;
}) {
    const [auto, setAuto] = useState(true);

    useEffect(() => {
        if (!auto) return;
        const id = window.setInterval(
            () => router.reload({ only: ['snapshot'] }),
            60_000,
        );
        return () => window.clearInterval(id);
    }, [auto]);

    const go = (facility: number | null) =>
        router.get(commandCentre().url, facility ? { facility } : {}, {
            preserveState: true,
            preserveScroll: true,
        });

    const asOf = new Intl.DateTimeFormat('en-IN', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone: timezone,
    }).format(new Date(snapshot.as_of));

    const running = snapshot.running.filter((r) => r.status === 'in_progress');
    const ready = snapshot.running.filter((r) => r.status === 'approved');

    return (
        <>
            <Head title="Command centre" />
            <div className="flex flex-1 flex-col gap-5 p-4 sm:p-6">
                <PageHeader
                    title="Factory command centre"
                    description={`What is running, what is delayed, what is waiting for QC, what is short, what is dispatching today, what needs a signature, and what could stop production tomorrow. As of ${asOf}.`}
                    actions={
                        <>
                            <FacilityFilter
                                facilities={facilities}
                                value={filters.facility}
                                onChange={go}
                            />
                            <Button
                                variant={auto ? 'secondary' : 'outline'}
                                size="sm"
                                onClick={() => setAuto((a) => !a)}
                                title="Refresh every minute"
                            >
                                <RefreshCw
                                    className={cn(
                                        'size-4',
                                        auto &&
                                            'animate-[spin_6s_linear_infinite]',
                                    )}
                                />
                                {auto ? 'Live' : 'Paused'}
                            </Button>
                        </>
                    }
                />

                {snapshot.tomorrow.length > 0 && (
                    <section className="rounded-2xl border border-red-500/30 bg-red-500/5 p-5">
                        <h2 className="flex items-center gap-2 font-semibold text-red-700 dark:text-red-300">
                            <AlertOctagon className="size-5" />
                            What could stop production tomorrow
                        </h2>
                        <ul className="mt-3 grid gap-3 md:grid-cols-2">
                            {snapshot.tomorrow.map((t, i) => (
                                <li
                                    key={i}
                                    className="bg-card rounded-xl border p-3 text-sm"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <span className="font-medium">
                                            {t.href ? (
                                                <Link
                                                    href={t.href}
                                                    className="underline-offset-4 hover:underline"
                                                >
                                                    {t.title}
                                                </Link>
                                            ) : (
                                                t.title
                                            )}
                                        </span>
                                        <StatusBadge
                                            variant={SEVERITY[t.severity]}
                                        >
                                            {t.kind === 'material'
                                                ? 'Material'
                                                : 'Batch'}
                                        </StatusBadge>
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {t.detail}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                    <Panel
                        icon={Factory}
                        title="Running"
                        count={running.length}
                        tone="default"
                    >
                        {running.length === 0 && ready.length === 0 ? (
                            <Empty>Nothing on the floor.</Empty>
                        ) : (
                            <div className="space-y-1">
                                {running.map((r) => (
                                    <Row
                                        key={r.id}
                                        href={r.href}
                                        tone={r.late ? 'late' : undefined}
                                        title={
                                            <>
                                                {r.number}
                                                <span className="text-muted-foreground font-normal">
                                                    {' '}
                                                    · {r.product ?? '—'}
                                                </span>
                                            </>
                                        }
                                        meta={
                                            <>
                                                {r.batch}
                                                {r.client
                                                    ? ` · ${r.client}`
                                                    : ''}
                                                {r.facility
                                                    ? ` · ${r.facility}`
                                                    : ''}
                                            </>
                                        }
                                        right={
                                            <span
                                                className={cn(
                                                    'text-xs tabular-nums',
                                                    r.late
                                                        ? 'font-semibold text-red-600'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                <Clock className="mr-1 inline size-3" />
                                                {hours(r.elapsed_hours)}
                                            </span>
                                        }
                                    />
                                ))}
                                {ready.length > 0 && (
                                    <p className="text-muted-foreground mt-2 px-2 text-xs font-medium tracking-wide uppercase">
                                        Ready to start ({ready.length})
                                    </p>
                                )}
                                {ready.map((r) => (
                                    <Row
                                        key={r.id}
                                        href={r.href}
                                        title={
                                            <>
                                                {r.number}
                                                <span className="text-muted-foreground font-normal">
                                                    {' '}
                                                    · {r.product ?? '—'}
                                                </span>
                                            </>
                                        }
                                        meta={r.batch}
                                        right={
                                            <StatusBadge variant="info">
                                                Reserved
                                            </StatusBadge>
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        icon={Clock}
                        title="Delayed"
                        count={snapshot.delayed.length}
                        tone={
                            snapshot.delayed.length > 0 ? 'danger' : 'success'
                        }
                    >
                        {snapshot.delayed.length === 0 ? (
                            <Empty>Nothing is late.</Empty>
                        ) : (
                            <div className="space-y-1">
                                {snapshot.delayed.map((e) => (
                                    <Row
                                        key={e.key}
                                        href={e.href}
                                        tone="late"
                                        title={e.title}
                                        meta={e.detail}
                                        right={
                                            <StatusBadge
                                                variant={SEVERITY[e.severity]}
                                            >
                                                {e.rule_label}
                                            </StatusBadge>
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        icon={ClipboardCheck}
                        title="Waiting for QC"
                        count={snapshot.awaiting_qc.length}
                        tone={
                            snapshot.awaiting_qc.some((q) => q.slow)
                                ? 'warning'
                                : 'default'
                        }
                    >
                        {snapshot.awaiting_qc.length === 0 ? (
                            <Empty>Quarantine is clear.</Empty>
                        ) : (
                            <div className="space-y-1">
                                {snapshot.awaiting_qc.map((q) => (
                                    <Row
                                        key={q.id}
                                        href={q.href}
                                        tone={q.slow ? 'late' : undefined}
                                        title={
                                            <>
                                                {q.item ?? q.number}
                                                <span className="text-muted-foreground font-normal">
                                                    {' '}
                                                    · {q.batch ?? q.number}
                                                </span>
                                            </>
                                        }
                                        meta={
                                            <>
                                                {q.quantity}
                                                {q.client
                                                    ? ` · ${q.client}`
                                                    : ''}
                                                {q.status === 'on_hold'
                                                    ? ' · on hold'
                                                    : ''}
                                            </>
                                        }
                                        right={
                                            <span
                                                className={cn(
                                                    'text-xs tabular-nums',
                                                    q.slow
                                                        ? 'font-semibold text-amber-600'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {hours(q.waiting_hours)}
                                            </span>
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        icon={PackageSearch}
                        title="Short"
                        count={snapshot.short.length}
                        tone={snapshot.short.length > 0 ? 'warning' : 'success'}
                    >
                        {snapshot.short.length === 0 ? (
                            <Empty>Every open plan has its materials.</Empty>
                        ) : (
                            <div className="space-y-1">
                                {snapshot.short.map((s) => (
                                    <div
                                        key={s.item_id}
                                        className="rounded-lg px-2 py-2 text-sm"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <div className="truncate font-medium">
                                                    {s.name}
                                                    {s.client_material && (
                                                        <StatusBadge
                                                            variant="info"
                                                            className="ml-2"
                                                        >
                                                            Client material
                                                        </StatusBadge>
                                                    )}
                                                </div>
                                                <div className="text-muted-foreground text-xs">
                                                    {s.plans
                                                        .map(
                                                            (p) =>
                                                                `${p.number}${p.start ? ` (${date(p.start)})` : ''}`,
                                                        )
                                                        .join(', ')}
                                                </div>
                                            </div>
                                            <div className="shrink-0 text-right text-xs">
                                                <div className="font-semibold text-amber-700 tabular-nums dark:text-amber-300">
                                                    −{s.shortage} {s.unit ?? ''}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {Number(s.requested) > 0
                                                        ? `${s.requested} requested`
                                                        : 'not requested'}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        icon={Truck}
                        title="Dispatching today"
                        count={
                            snapshot.dispatching.due.length +
                            snapshot.dispatching.awaiting_dispatch.length +
                            (snapshot.dispatching.online?.to_pack ?? 0)
                        }
                        tone={
                            snapshot.dispatching.due.some((d) => d.overdue) ||
                            snapshot.dispatching.online?.past_cutoff
                                ? 'danger'
                                : 'default'
                        }
                    >
                        {snapshot.dispatching.due.length === 0 &&
                        snapshot.dispatching.awaiting_dispatch.length === 0 &&
                        snapshot.dispatching.online === null ? (
                            <Empty>Nothing due out today.</Empty>
                        ) : (
                            <div className="space-y-1">
                                {snapshot.dispatching.online && (
                                    <Row
                                        href={snapshot.dispatching.online.href}
                                        tone={
                                            snapshot.dispatching.online
                                                .past_cutoff
                                                ? 'late'
                                                : undefined
                                        }
                                        title={
                                            <>
                                                Online orders
                                                <span className="text-muted-foreground font-normal">
                                                    {' '}
                                                    ·{' '}
                                                    {
                                                        snapshot.dispatching
                                                            .online.parcels
                                                    }{' '}
                                                    parcels
                                                </span>
                                            </>
                                        }
                                        meta={`${snapshot.dispatching.online.to_pack} to pack by ${snapshot.dispatching.online.cutoff} · ${snapshot.dispatching.online.packed} packed · ${snapshot.dispatching.online.handed_over} with courier`}
                                        right={
                                            snapshot.dispatching.online
                                                .past_cutoff ? (
                                                <StatusBadge variant="destructive">
                                                    Past cut-off
                                                </StatusBadge>
                                            ) : snapshot.dispatching.online
                                                  .to_pack === 0 ? (
                                                <StatusBadge variant="success">
                                                    All packed
                                                </StatusBadge>
                                            ) : (
                                                <StatusBadge variant="warning">
                                                    Packing
                                                </StatusBadge>
                                            )
                                        }
                                    />
                                )}
                                {snapshot.dispatching.due.map((d) => (
                                    <Row
                                        key={d.id}
                                        href={d.href}
                                        tone={d.overdue ? 'late' : undefined}
                                        title={
                                            <>
                                                {d.client ?? d.number}
                                                <span className="text-muted-foreground font-normal">
                                                    {' '}
                                                    · {d.product ?? d.number}
                                                </span>
                                            </>
                                        }
                                        meta={
                                            <>
                                                {d.client_po_ref
                                                    ? `PO ${d.client_po_ref} · `
                                                    : ''}
                                                {d.overdue
                                                    ? 'was due '
                                                    : 'due '}
                                                {date(d.required_delivery_at)}
                                            </>
                                        }
                                        right={
                                            d.ready ? (
                                                <StatusBadge variant="success">
                                                    Ready
                                                </StatusBadge>
                                            ) : (
                                                <StatusBadge variant="warning">
                                                    {d.status_label}
                                                </StatusBadge>
                                            )
                                        }
                                    />
                                ))}
                                {snapshot.dispatching.awaiting_dispatch.map(
                                    (a) => (
                                        <Row
                                            key={a.lot_id}
                                            href={a.href}
                                            title={
                                                <>
                                                    {a.client ?? '—'}
                                                    <span className="text-muted-foreground font-normal">
                                                        {' '}
                                                        ·{' '}
                                                        {a.product ??
                                                            a.batch_number}
                                                    </span>
                                                </>
                                            }
                                            meta={`${a.batch_number} · ${a.on_hand} on hand · made ${date(a.manufactured_at)}`}
                                            right={
                                                <StatusBadge variant="success">
                                                    Awaiting dispatch
                                                </StatusBadge>
                                            }
                                        />
                                    ),
                                )}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        icon={Signature}
                        title="Requires approval"
                        count={snapshot.approvals.length}
                        tone={
                            snapshot.approvals.length > 0
                                ? 'warning'
                                : 'success'
                        }
                    >
                        {snapshot.approvals.length === 0 ? (
                            <Empty>Nothing waiting on a signature.</Empty>
                        ) : (
                            <div className="space-y-1">
                                {snapshot.approvals.map((a, i) => (
                                    <Row
                                        key={`${a.kind}-${a.number}-${i}`}
                                        href={a.href}
                                        title={a.number}
                                        meta={
                                            <>
                                                {a.label}
                                                {a.who ? ` · by ${a.who}` : ''}
                                            </>
                                        }
                                        right={
                                            a.since ? (
                                                <span className="text-muted-foreground text-xs">
                                                    {date(a.since)}
                                                </span>
                                            ) : undefined
                                        }
                                    />
                                ))}
                            </div>
                        )}
                    </Panel>
                </div>

                <Panel
                    icon={AlertTriangle}
                    title="Exceptions"
                    count={snapshot.exceptions.length}
                    tone={
                        snapshot.counts.exceptions_high > 0
                            ? 'danger'
                            : snapshot.exceptions.length > 0
                              ? 'warning'
                              : 'success'
                    }
                >
                    {snapshot.exceptions.length === 0 ? (
                        <Empty>
                            Nothing abnormal: wastage, variance, prices, QC,
                            yields and delays are all within limits.
                        </Empty>
                    ) : (
                        <div className="grid gap-2 md:grid-cols-2">
                            {snapshot.exceptions.map((e) => (
                                <div
                                    key={e.key}
                                    className={cn(
                                        'rounded-xl border p-3 text-sm',
                                        e.severity === 'high' &&
                                            'border-red-500/30 bg-red-500/5',
                                    )}
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        {e.href ? (
                                            <Link
                                                href={e.href}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {e.title}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {e.title}
                                            </span>
                                        )}
                                        <StatusBadge
                                            variant={SEVERITY[e.severity]}
                                        >
                                            {e.rule_label}
                                        </StatusBadge>
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {e.detail}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-[11px]">
                                        Standing for {hours(e.age_hours)}
                                    </p>
                                </div>
                            ))}
                        </div>
                    )}
                </Panel>
            </div>
        </>
    );
}

CommandCentre.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Command centre', href: commandCentre() },
    ],
};
