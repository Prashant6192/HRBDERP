import { Head } from '@inertiajs/react';
import { date } from '@/lib/stock';
import { Empty, Pill, Row, rupees, Section, Stat, Title } from './parts';

type Job = {
    id: number;
    number: string;
    product: string | null;
    client: string | null;
    output: string | null;
    output_units: number | null;
    batch: string | null;
    completed_at: string | null;
    manufacturing_charge: string;
    material_charge: string;
    gst: string;
    total: string;
    has_terms: boolean;
    href: string;
};

type Draft = {
    id: number;
    number: string;
    customer: string | null;
    lines: number;
    total: string;
    created_at: string | null;
    href: string;
};

export default function ManagementBilling({
    jobs,
    jobs_total,
    dispatches,
    dispatches_total,
    total,
}: {
    jobs: Job[];
    jobs_total: string;
    dispatches: Draft[];
    dispatches_total: string;
    total: string;
}) {
    return (
        <>
            <Head title="To be billed" />
            <Title hint="Third-party jobs made but not yet sent out, and consignments written up but not yet invoiced.">
                To be billed
            </Title>

            <div className="grid grid-cols-2 gap-3">
                <Stat
                    label="Total awaiting billing"
                    value={rupees(total)}
                    tone={Number(total) > 0 ? 'warn' : 'default'}
                />
                <Stat
                    label="3P jobs"
                    value={rupees(jobs_total)}
                    hint={`${jobs.length} completed, not dispatched`}
                />
            </div>

            <Section
                title="Completed jobs to bill"
                hint="At the agreed terms: manufacturing charge, material where billed, GST."
            >
                {jobs.length === 0 ? (
                    <Empty>Every completed third-party job has gone out.</Empty>
                ) : (
                    <div className="divide-y">
                        {jobs.map((j) => (
                            <Row
                                key={j.id}
                                href={j.href}
                                title={`${j.client ?? '—'} · ${j.product ?? ''}`}
                                subtitle={`${j.number}${j.batch ? ` · batch ${j.batch}` : ''}${j.output_units ? ` · ${j.output_units.toLocaleString('en-IN')} units` : ''} · ${date(j.completed_at)}`}
                                right={rupees(j.total)}
                                rightHint={
                                    j.has_terms
                                        ? `incl. GST ${rupees(j.gst)}`
                                        : undefined
                                }
                                badge={
                                    !j.has_terms ? (
                                        <Pill tone="warn">no terms set</Pill>
                                    ) : undefined
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>

            <Section
                title="Draft dispatches"
                hint={`${rupees(dispatches_total)} written up, not yet invoiced`}
            >
                {dispatches.length === 0 ? (
                    <Empty>No consignment is waiting for an invoice.</Empty>
                ) : (
                    <div className="divide-y">
                        {dispatches.map((d) => (
                            <Row
                                key={d.id}
                                href={d.href}
                                title={d.customer ?? d.number}
                                subtitle={`${d.number} · ${d.lines} line${d.lines === 1 ? '' : 's'} · ${date(d.created_at)}`}
                                right={rupees(d.total)}
                            />
                        ))}
                    </div>
                )}
            </Section>
        </>
    );
}
