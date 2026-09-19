import { Head } from '@inertiajs/react';
import { date } from '@/lib/stock';
import { show as showFormula } from '@/routes/formulas';
import { Empty, Pill, Row, Section, Title } from './parts';

type Formula = {
    id: number;
    code: string;
    name: string;
    product: string | null;
    status: string;
    status_label: string;
    ownership: string;
    ownership_label: string;
    client: string | null;
    version: number | null;
    versions: number;
    updated_at: string | null;
};

export default function ManagementFormulas({
    formulas,
}: {
    formulas: Formula[];
}) {
    const live = formulas.filter((f) => f.status === 'active');
    const rest = formulas.filter((f) => f.status !== 'active');

    return (
        <>
            <Head title="Formulas" />
            <Title hint="Which recipes are live, whose they are, and which version the floor makes.">
                Formulas on file
            </Title>

            <Section title="Live" hint={`${live.length} active`}>
                {live.length === 0 ? (
                    <Empty>No formula is active yet.</Empty>
                ) : (
                    <div className="divide-y">
                        {live.map((f) => (
                            <Row
                                key={f.id}
                                href={showFormula(f.id).url}
                                title={f.name}
                                subtitle={`${f.code}${f.product ? ` · ${f.product}` : ''} · ${f.client ?? f.ownership_label}`}
                                right={f.version ? `v${f.version}` : '—'}
                                rightHint={
                                    f.updated_at
                                        ? date(f.updated_at)
                                        : undefined
                                }
                            />
                        ))}
                    </div>
                )}
            </Section>

            {rest.length > 0 && (
                <Section title="Not live" hint="Drafts and retired recipes.">
                    <div className="divide-y">
                        {rest.map((f) => (
                            <Row
                                key={f.id}
                                href={showFormula(f.id).url}
                                title={f.name}
                                subtitle={`${f.code}${f.product ? ` · ${f.product}` : ''} · ${f.versions} version${f.versions === 1 ? '' : 's'}`}
                                badge={<Pill>{f.status_label}</Pill>}
                            />
                        ))}
                    </div>
                </Section>
            )}
        </>
    );
}
