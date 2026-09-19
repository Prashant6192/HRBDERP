import { Head } from '@inertiajs/react';
import { date } from '@/lib/stock';
import { Empty, Pill, Row, Section, Title } from './parts';

type Running = {
    id: number;
    number: string;
    product: string | null;
    client: string | null;
    batch: string;
    status: string;
    status_label: string;
    stage: string | null;
    progress: number | null;
    elapsed_hours: number | null;
    late: boolean;
    href: string;
};

type Qc = {
    id: number;
    number: string;
    item: string | null;
    batch: string | null;
    quantity: string;
    waiting_hours: number;
    slow: boolean;
    href: string;
};

type Completed = {
    id: number;
    number: string;
    product: string | null;
    client: string | null;
    planned: string;
    output: string | null;
    planned_units: number | null;
    output_units: number | null;
    rejected_units: number | null;
    yield: string | null;
    overall_yield: string | null;
    batch: string | null;
    completed_at: string | null;
    href: string;
};

export default function ManagementProduction({
    running,
    awaiting_qc,
    recent,
}: {
    running: Running[];
    awaiting_qc: Qc[];
    recent: Completed[];
}) {
    return (
        <>
            <Head title="Manufacturing" />
            <Title hint="Batches on the floor, batches waiting for QC, and what came out lately.">
                Manufacturing
            </Title>

            <Section
                title="On the floor"
                hint={`${running.length} batch${running.length === 1 ? '' : 'es'}`}
            >
                {running.length === 0 ? (
                    <Empty>No batch is running or ready to start.</Empty>
                ) : (
                    <div className="divide-y">
                        {running.map((r) => (
                            <Row
                                key={r.id}
                                href={r.href}
                                title={`${r.number} · ${r.product ?? '—'}`}
                                subtitle={`${r.batch}${r.client ? ` · for ${r.client}` : ''}${r.stage ? ` · ${r.stage}` : ''}`}
                                right={
                                    r.progress === null
                                        ? r.status_label
                                        : `${r.progress}%`
                                }
                                rightHint={
                                    r.elapsed_hours !== null
                                        ? `${r.elapsed_hours} h`
                                        : undefined
                                }
                                badge={
                                    r.late ? (
                                        <Pill tone="bad">late</Pill>
                                    ) : r.status === 'approved' ? (
                                        <Pill>ready to start</Pill>
                                    ) : undefined
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>

            <Section
                title="Awaiting QC"
                hint="Batches in quarantine until QC signs them off."
            >
                {awaiting_qc.length === 0 ? (
                    <Empty>Nothing is waiting for QC.</Empty>
                ) : (
                    <div className="divide-y">
                        {awaiting_qc.map((q) => (
                            <Row
                                key={q.id}
                                href={q.href}
                                title={q.item ?? q.number}
                                subtitle={`${q.batch ?? ''} · ${q.quantity}`}
                                right={`${q.waiting_hours} h`}
                                badge={
                                    q.slow ? (
                                        <Pill tone="warn">slow</Pill>
                                    ) : undefined
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>

            <Section
                title="Recently completed"
                hint="Planned against made, and the good units that reached stock."
            >
                {recent.length === 0 ? (
                    <Empty>No batch has been completed yet.</Empty>
                ) : (
                    <div className="divide-y">
                        {recent.map((c) => (
                            <Row
                                key={c.id}
                                href={c.href}
                                title={`${c.number} · ${c.product ?? '—'}`}
                                subtitle={`${c.planned} planned → ${c.output ?? '—'}${c.batch ? ` · batch ${c.batch}` : ''} · ${date(c.completed_at)}`}
                                right={
                                    c.output_units !== null
                                        ? `${c.output_units.toLocaleString('en-IN')} units`
                                        : c.yield
                                          ? `${c.yield}%`
                                          : '—'
                                }
                                rightHint={
                                    c.overall_yield
                                        ? `${c.overall_yield}% of plan${c.rejected_units ? ` · ${c.rejected_units} rejected` : ''}`
                                        : c.yield
                                          ? `${c.yield}% yield`
                                          : undefined
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>
        </>
    );
}
