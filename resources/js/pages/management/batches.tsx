import { Head } from '@inertiajs/react';
import { ArtworkGallery } from '@/components/contract/artwork-gallery';
import { date } from '@/lib/stock';
import type { ArtworkRow } from '@/types';
import { Empty, Row, Section, Stat, Title } from './parts';

type Batch = {
    lot_id: number;
    batch_number: string;
    product: string;
    product_code: string;
    client: string | null;
    on_hand: string;
    unit: string | null;
    stores: string;
    manufactured_at: string | null;
    expiry_at: string | null;
    order: {
        id: number;
        number: string;
        planned: string;
        planned_units: number | null;
        output_units: number | null;
        rejected_units: number | null;
        yield: string | null;
        overall_yield: string | null;
    } | null;
    artworks: ArtworkRow[];
    href: string;
};

export default function ManagementBatches({
    batches,
    total_units,
}: {
    batches: Batch[];
    total_units: string;
}) {
    return (
        <>
            <Head title="Batches ready" />
            <Title hint="Finished batches that passed QC and are on the shelf, with the artwork each was packed to.">
                Batches ready
            </Title>

            <div className="grid grid-cols-2 gap-3">
                <Stat
                    label="Batches on the shelf"
                    value={batches.length}
                    tone="good"
                />
                <Stat
                    label="Units ready"
                    value={Number(total_units).toLocaleString('en-IN')}
                />
            </div>

            {batches.length === 0 ? (
                <Section title="Nothing ready">
                    <Empty>No finished batch has passed QC yet.</Empty>
                </Section>
            ) : (
                batches.map((b) => (
                    <Section
                        key={b.lot_id}
                        title={`${b.product} · ${b.batch_number}`}
                        hint={`${b.stores}${b.client ? ` · for ${b.client}` : ''}${b.expiry_at ? ` · expires ${date(b.expiry_at)}` : ''}`}
                    >
                        <Row
                            href={b.href}
                            title="On the shelf"
                            subtitle={
                                b.order
                                    ? `${b.order.number} · ${b.order.planned} planned${b.order.planned_units ? ` for ${b.order.planned_units.toLocaleString('en-IN')} units` : ''}`
                                    : 'Booked in as opening stock'
                            }
                            right={`${Number(b.on_hand).toLocaleString('en-IN')} ${b.unit ?? ''}`}
                            rightHint={
                                b.order?.overall_yield
                                    ? `${b.order.overall_yield}% of plan${b.order.rejected_units ? ` · ${b.order.rejected_units} rejected` : ''}`
                                    : b.order?.yield
                                      ? `${b.order.yield}% yield`
                                      : undefined
                            }
                        />
                        <div className="border-t px-4 py-3">
                            <p className="text-muted-foreground mb-2 text-xs">
                                {b.artworks.length > 0
                                    ? 'Packed to this approved artwork'
                                    : 'No approved artwork on file for this product'}
                            </p>
                            {b.artworks.length > 0 && (
                                <ArtworkGallery artworks={b.artworks} compact />
                            )}
                        </div>
                    </Section>
                ))
            )}
        </>
    );
}
