import { Head } from '@inertiajs/react';
import { date } from '@/lib/stock';
import { show as showClient } from '@/routes/clients';
import { Empty, Pill, Row, Section, Title } from './parts';

type Client = {
    id: number;
    code: string;
    name: string;
    city: string | null;
    contact: string | null;
    phone: string | null;
    is_active: boolean;
    products: number;
    open_jobs: number;
    running_jobs: number;
    completed_jobs: number;
    payment_terms_days: number | null;
    last_completed_at: string | null;
};

export default function ManagementClients({ clients }: { clients: Client[] }) {
    return (
        <>
            <Head title="3P clients" />
            <Title hint="Who the company manufactures for, and how much is in hand for each.">
                Third-party clients
            </Title>

            <Section
                title="Clients"
                hint={`${clients.filter((c) => c.is_active).length} active`}
            >
                {clients.length === 0 ? (
                    <Empty>No third-party client on file.</Empty>
                ) : (
                    <div className="divide-y">
                        {clients.map((c) => (
                            <Row
                                key={c.id}
                                href={showClient(c.id).url}
                                title={c.name}
                                subtitle={`${c.products} product${c.products === 1 ? '' : 's'} · ${c.completed_jobs} batch${c.completed_jobs === 1 ? '' : 'es'} made${c.last_completed_at ? `, last ${date(c.last_completed_at)}` : ''}${c.contact ? ` · ${c.contact}` : ''}`}
                                right={
                                    c.open_jobs > 0
                                        ? `${c.open_jobs} open`
                                        : '—'
                                }
                                rightHint={
                                    c.running_jobs > 0
                                        ? `${c.running_jobs} running`
                                        : undefined
                                }
                                badge={
                                    !c.is_active ? (
                                        <Pill>inactive</Pill>
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
