import { Head } from '@inertiajs/react';
import {
    Boxes,
    Factory,
    FlaskConical,
    Handshake,
    IndianRupee,
    PackageCheck,
    ShoppingCart,
} from 'lucide-react';
import {
    batches,
    billing,
    clients,
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

            <p className="text-muted-foreground mt-4 text-xs">
                {o.production.completed_this_month} batch
                {o.production.completed_this_month === 1 ? '' : 'es'} completed
                this month. Everything here is read live from the ERP; nothing
                is entered on these screens.
            </p>
        </>
    );
}
