import { Head, Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { materials } from '@/routes/management';
import { Empty, Pill, Row, rupees, Section, Stat, Title } from './parts';

type Item = {
    item_id: number;
    code: string;
    name: string;
    type: string;
    unit: string | null;
    on_hand: string;
    reserved: string;
    in_quarantine: string;
    value: string;
    quarantine_value: string;
    batches: number;
    below_reorder: boolean;
    href: string;
};

export default function ManagementMaterials({
    type,
    type_label,
    types,
    items,
    total_value,
    quarantine_value,
    by_type,
}: {
    type: string;
    type_label: string;
    types: { value: string; label: string }[];
    items: Item[];
    total_value: string;
    quarantine_value: string;
    by_type: Record<string, { value: string; items: number }>;
}) {
    return (
        <>
            <Head title="Stock" />
            <Title hint="What is on the shelf and what it is worth, at batch cost.">
                Stock and its value
            </Title>

            <div className="grid grid-cols-2 gap-3">
                <Stat
                    label={`${type_label} value`}
                    value={rupees(total_value)}
                    hint={`${items.length} item${items.length === 1 ? '' : 's'} with stock`}
                />
                <Stat
                    label="Of which in quarantine"
                    value={rupees(quarantine_value)}
                    hint="waiting for QC"
                    tone={Number(quarantine_value) > 0 ? 'warn' : 'default'}
                />
            </div>

            <div className="mt-4 flex gap-2 overflow-x-auto pb-1">
                {types.map((t) => (
                    <Link
                        key={t.value}
                        href={`${materials().url}?type=${t.value}`}
                        preserveScroll
                        className={cn(
                            'shrink-0 rounded-full border px-3 py-1.5 text-xs font-medium',
                            t.value === type
                                ? 'bg-primary text-primary-foreground border-primary'
                                : 'bg-card',
                        )}
                    >
                        {t.label}
                        {by_type[t.value]
                            ? ` · ${rupees(by_type[t.value].value)}`
                            : ''}
                    </Link>
                ))}
            </div>

            <Section
                title={type_label}
                hint="Highest value first. Tap for batches and history."
            >
                {items.length === 0 ? (
                    <Empty>No {type_label.toLowerCase()} in stock.</Empty>
                ) : (
                    <div className="divide-y">
                        {items.map((i) => (
                            <Row
                                key={i.item_id}
                                href={i.href}
                                title={i.name}
                                subtitle={`${i.code} · ${i.batches} batch${i.batches === 1 ? '' : 'es'}${Number(i.reserved) > 0 ? ` · ${i.reserved} ${i.unit ?? ''} held for batches` : ''}${Number(i.in_quarantine) > 0 ? ` · ${i.in_quarantine} ${i.unit ?? ''} in QC` : ''}`}
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
