import { Head, Link } from '@inertiajs/react';
import {
    type DepotSummary,
    LorryLine,
    OnlineTodayCard,
    WeekBars,
} from '@/components/management/depot-card';
import { cn } from '@/lib/utils';
import { depot as depotRoute } from '@/routes/management';
import { Empty, Pill, Row, rupees, Section, Stat, Title } from './parts';

type Item = {
    item_id: number;
    code: string;
    name: string;
    type: string;
    unit: string | null;
    on_hand: string;
    reserved: string;
    value: string;
    batches: number;
    below_reorder: boolean;
    href: string;
};

type Dispatch = {
    id: number;
    number: string;
    customer: string | null;
    status: string;
    status_label: string;
    total_value: string;
    date: string | null;
    href: string;
};

type Detail = DepotSummary & {
    items: Item[];
    recent_dispatches: Dispatch[];
};

const STATUS_TONE: Record<string, 'muted' | 'warn' | 'good'> = {
    draft: 'muted',
    invoiced: 'warn',
    dispatched: 'good',
    delivered: 'good',
};

export default function ManagementDepot({
    depots,
    depot,
}: {
    depots: { id: number; name: string; short: string }[];
    depot: Detail | null;
}) {
    if (depot === null) {
        return (
            <>
                <Head title="Depot" />
                <Title>Depot</Title>
                <Empty>No depot or warehouse is set up for you yet.</Empty>
            </>
        );
    }

    const d = depot;
    const shipped = d.week.reduce((n, w) => n + w.shipped, 0);

    return (
        <>
            <Head title={d.short} />
            <Title
                hint={`As of ${new Date(d.as_of).toLocaleString('en-IN', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' })}. Read live from the ERP.`}
            >
                {d.name}
            </Title>

            {depots.length > 1 && (
                <div className="mb-3 flex gap-2 overflow-x-auto pb-1">
                    {depots.map((p) => (
                        <Link
                            key={p.id}
                            href={`${depotRoute().url}?facility=${p.id}`}
                            className={cn(
                                'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium',
                                p.id === d.id
                                    ? 'bg-primary text-primary-foreground border-primary'
                                    : 'bg-card',
                            )}
                        >
                            {p.short}
                        </Link>
                    ))}
                </div>
            )}

            <OnlineTodayCard online={d.online} />

            {d.online.batches.length > 0 && (
                <Section
                    title="Today's uploads"
                    hint="Each upload by the agency, and what is left to pack in it."
                >
                    <div className="divide-y">
                        {d.online.batches.map((b) => (
                            <Row
                                key={b.id}
                                title={b.number}
                                subtitle={[b.brand, b.marketplace]
                                    .filter(Boolean)
                                    .join(' · ')}
                                right={`${b.to_pack} to pack`}
                                rightHint={`of ${b.total}`}
                                badge={
                                    <Pill
                                        tone={
                                            b.status === 'open'
                                                ? 'warn'
                                                : 'good'
                                        }
                                    >
                                        {b.status === 'open'
                                            ? 'uploading'
                                            : 'ready'}
                                    </Pill>
                                }
                            />
                        ))}
                    </div>
                </Section>
            )}

            <Section
                title="Parcels shipped"
                hint={`${shipped} handed to couriers in the last 7 days.`}
            >
                <div className="px-4 py-3">
                    <WeekBars week={d.week} />
                </div>
            </Section>

            <div className="mt-4 grid grid-cols-2 gap-3">
                <Stat
                    label="Dispatches to send"
                    value={d.dispatches.pending}
                    hint={rupees(d.dispatches.pending_value)}
                    tone={d.dispatches.pending > 0 ? 'warn' : 'default'}
                />
                <Stat
                    label="Sent this month"
                    value={d.dispatches.this_month}
                    hint={rupees(d.dispatches.this_month_value)}
                    tone="good"
                />
            </div>

            <Section title="Dispatches" hint="The latest ten, newest first.">
                {d.recent_dispatches.length === 0 ? (
                    <Empty>No dispatches from here yet.</Empty>
                ) : (
                    <div className="divide-y">
                        {d.recent_dispatches.map((x) => (
                            <Row
                                key={x.id}
                                href={x.href}
                                title={x.customer ?? x.number}
                                subtitle={`${x.number}${x.date ? ` · ${new Date(x.date).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}` : ''}`}
                                right={rupees(x.total_value)}
                                badge={
                                    <Pill
                                        tone={STATUS_TONE[x.status] ?? 'muted'}
                                    >
                                        {x.status_label}
                                    </Pill>
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>

            <div className="mt-4 grid grid-cols-2 gap-3">
                <Stat
                    label="Stock value"
                    value={rupees(d.stock.value)}
                    hint={`${d.stock.items} item${d.stock.items === 1 ? '' : 's'} · ${Number(d.stock.units).toLocaleString('en-IN')} units`}
                />
                <Stat
                    label="Running low"
                    value={d.stock.critical + d.stock.low}
                    hint={`${d.stock.critical} critical · ${d.stock.low} low`}
                    tone={d.stock.critical > 0 ? 'warn' : 'default'}
                />
            </div>
            <LorryLine incoming={d.incoming} />

            <Section
                title={`Stock at ${d.short}`}
                hint="Highest value first. Tap for batches and history."
            >
                {d.items.length === 0 ? (
                    <Empty>Nothing in stock here yet.</Empty>
                ) : (
                    <div className="divide-y">
                        {d.items.map((i) => (
                            <Row
                                key={i.item_id}
                                href={i.href}
                                title={i.name}
                                subtitle={`${i.code} · ${i.batches} batch${i.batches === 1 ? '' : 'es'}${Number(i.reserved) > 0 ? ` · ${i.reserved} ${i.unit ?? ''} held for orders` : ''}`}
                                right={`${Number(i.on_hand).toLocaleString('en-IN')} ${i.unit ?? ''}`}
                                rightHint={rupees(i.value)}
                                badge={
                                    i.below_reorder ? (
                                        <Pill tone="warn">below reorder</Pill>
                                    ) : undefined
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>
        </>
    );
}
