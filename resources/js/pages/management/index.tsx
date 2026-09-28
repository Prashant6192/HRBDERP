import { Head } from '@inertiajs/react';
import {
    Boxes,
    Factory,
    FlaskConical,
    Handshake,
    IndianRupee,
    PackageCheck,
    ShoppingCart,
    Send,
    Warehouse,
} from 'lucide-react';
import {
    type DepotSummary,
    LorryLine,
    OnlineTodayCard,
    WeekBars,
} from '@/components/management/depot-card';
import {
    batches,
    billing,
    clients,
    depot,
    formulas,
    materials,
    ordering,
    production,
} from '@/routes/management';
import { rupees, Stat, Title } from './parts';

type Overview = {
    as_of: string;
    production: {
        in_progress: number;
        approved: number;
        completed_this_month: number;
        awaiting_qc: number;
    };
    stock: {
        raw_material_value: string;
        packaging_value: string;
        finished_goods_value: string;
        raw_material_items: number;
        quarantine_value: string;
    };
    ordering: { order_today: number; order_soon: number; watch: number };
    formulas: { active: number; total: number };
    clients: { active: number; open_jobs: number };
    batches: { ready: number; ready_units: string };
    billing: {
        jobs: number;
        jobs_total: string;
        dispatches: number;
        dispatches_total: string;
        total: string;
    };
    depots: DepotSummary[];
};

export default function ManagementHome({ overview }: { overview: Overview }) {
    const o = overview;

    return (
        <>
            <Head title="Management" />
            <Title
                hint={`As of ${new Date(o.as_of).toLocaleString('en-IN', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' })}. Tap a card for the detail.`}
            >
                The factory today
            </Title>

            <div className="grid grid-cols-2 gap-3">
                <Stat
                    label="In manufacturing"
                    value={o.production.in_progress}
                    hint={`${o.production.approved} ready to start · ${o.production.awaiting_qc} awaiting QC`}
                    icon={Factory}
                    href={production().url}
                    tone="primary"
                />
                <Stat
                    label="Batches ready"
                    value={o.batches.ready}
                    hint={`${Number(o.batches.ready_units).toLocaleString('en-IN')} units on the shelf`}
                    icon={PackageCheck}
                    href={batches().url}
                    tone="good"
                />
                <Stat
                    label="Raw material in stock"
                    value={rupees(o.stock.raw_material_value)}
                    hint={`${o.stock.raw_material_items} materials · packaging ${rupees(o.stock.packaging_value)}`}
                    icon={Boxes}
                    href={materials().url}
                />
                <Stat
                    label="To order"
                    value={o.ordering.order_today}
                    hint={`today · ${o.ordering.order_soon} soon · ${o.ordering.watch} to watch`}
                    icon={ShoppingCart}
                    href={ordering().url}
                    tone={o.ordering.order_today > 0 ? 'warn' : 'default'}
                />
                <Stat
                    label="To be billed"
                    value={rupees(o.billing.total)}
                    hint={`${o.billing.jobs} job${o.billing.jobs === 1 ? '' : 's'} · ${o.billing.dispatches} draft dispatch${o.billing.dispatches === 1 ? '' : 'es'}`}
                    icon={IndianRupee}
                    href={billing().url}
                    tone={Number(o.billing.total) > 0 ? 'warn' : 'default'}
                />
                <Stat
                    label="Finished goods value"
                    value={rupees(o.stock.finished_goods_value)}
                    hint={
                        Number(o.stock.quarantine_value) > 0
                            ? `${rupees(o.stock.quarantine_value)} held in quarantine`
                            : 'nothing in quarantine'
                    }
                    icon={PackageCheck}
                    href={`${materials().url}?type=finished_good`}
                />
                <Stat
                    label="Formulas live"
                    value={o.formulas.active}
                    hint={`of ${o.formulas.total} on file`}
                    icon={FlaskConical}
                    href={formulas().url}
                />
                <Stat
                    label="3P clients"
                    value={o.clients.active}
                    hint={`${o.clients.open_jobs} open job${o.clients.open_jobs === 1 ? '' : 's'}`}
                    icon={Handshake}
                    href={clients().url}
                />
            </div>

            {o.depots.map((d) => {
                const href = `${depot().url}?facility=${d.id}`;
                const shipped = d.week.reduce((n, w) => n + w.shipped, 0);

                return (
                    <section key={d.id} className="mt-6">
                        <Title hint="Stock, today's online orders and dispatches.">
                            {d.short} today
                        </Title>
                        <OnlineTodayCard online={d.online} href={href} />
                        <div className="mt-3 grid grid-cols-2 gap-3">
                            <Stat
                                label={`Stock at ${d.short}`}
                                value={rupees(d.stock.value)}
                                hint={`${d.stock.items} item${d.stock.items === 1 ? '' : 's'} · ${Number(d.stock.units).toLocaleString('en-IN')} units`}
                                icon={Warehouse}
                                href={href}
                            />
                            <Stat
                                label="Running low"
                                value={d.stock.critical + d.stock.low}
                                hint={`${d.stock.critical} critical · ${d.stock.low} low`}
                                icon={Boxes}
                                href={href}
                                tone={d.stock.critical > 0 ? 'warn' : 'default'}
                            />
                            <Stat
                                label="Dispatches to send"
                                value={d.dispatches.pending}
                                hint={`${rupees(d.dispatches.pending_value)} · ${d.dispatches.this_month} sent this month`}
                                icon={Send}
                                href={href}
                                tone={
                                    d.dispatches.pending > 0
                                        ? 'warn'
                                        : 'default'
                                }
                            />
                            <div className="bg-card rounded-2xl border p-4">
                                <div className="text-muted-foreground text-xs">
                                    Parcels shipped, 7 days
                                </div>
                                <div className="mt-1 text-2xl font-semibold tabular-nums">
                                    {shipped}
                                </div>
                                <WeekBars week={d.week} />
                            </div>
                        </div>
                        <LorryLine incoming={d.incoming} />
                    </section>
                );
            })}

            <p className="text-muted-foreground mt-4 text-xs">
                {o.production.completed_this_month} batch
                {o.production.completed_this_month === 1 ? '' : 'es'} completed
                this month. Everything here is read live from the ERP; nothing
                is entered on these screens.
            </p>
        </>
    );
}
