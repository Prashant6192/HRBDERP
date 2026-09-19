import { Head } from '@inertiajs/react';
import { date } from '@/lib/stock';
import { Empty, Pill, Row, Section, Title } from './parts';

type Outlook = {
    item_id: number;
    code: string;
    name: string;
    type: string;
    unit: string | null;
    on_hand: string;
    usable: string;
    days_of_cover: number | null;
    runs_out_at: string | null;
    recommended_quantity: string;
    order_by: string | null;
    status: string;
    vendor: { id: number; name: string } | string | null;
    sentences: string[];
};

const TONE: Record<string, 'bad' | 'warn' | 'muted'> = {
    order_today: 'bad',
    order_soon: 'warn',
    watch: 'muted',
};

const LABEL: Record<string, string> = {
    order_today: 'order today',
    order_soon: 'order soon',
    watch: 'watch',
};

export default function ManagementOrdering({ items }: { items: Outlook[] }) {
    return (
        <>
            <Head title="To order" />
            <Title hint="From consumption, lead times and the plans ahead. Most urgent first.">
                What needs ordering
            </Title>

            <Section
                title="Purchase advice"
                hint={`${items.length} material${items.length === 1 ? '' : 's'}`}
            >
                {items.length === 0 ? (
                    <Empty>Nothing needs ordering right now.</Empty>
                ) : (
                    <div className="divide-y">
                        {items.map((i) => (
                            <div key={i.item_id} className="px-4 py-3">
                                <Row
                                    title={i.name}
                                    subtitle={`${Number(i.usable).toLocaleString('en-IN')} ${i.unit ?? ''} usable${i.days_of_cover !== null ? ` · ${i.days_of_cover} days of cover` : ''}${i.runs_out_at ? ` · runs out ${date(i.runs_out_at)}` : ''}`}
                                    right={`${Number(i.recommended_quantity).toLocaleString('en-IN')} ${i.unit ?? ''}`}
                                    rightHint={
                                        i.order_by
                                            ? `by ${date(i.order_by)}`
                                            : undefined
                                    }
                                    badge={
                                        <Pill tone={TONE[i.status] ?? 'muted'}>
                                            {LABEL[i.status] ?? i.status}
                                        </Pill>
                                    }
                                />
                                {i.sentences[0] && (
                                    <p className="text-muted-foreground -mt-1 text-xs">
                                        {i.sentences[0]}
                                        {typeof i.vendor === 'object' &&
                                        i.vendor?.name
                                            ? ` Usual supplier: ${i.vendor.name}.`
                                            : ''}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Section>
        </>
    );
}
