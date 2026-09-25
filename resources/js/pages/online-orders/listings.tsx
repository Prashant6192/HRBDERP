import { Head, useForm } from '@inertiajs/react';
import { Plus, Tags, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    SearchableSelect,
    type SearchableOption,
} from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import { store, update } from '@/routes/listings';

type Waiting = {
    marketplace_id: number;
    marketplace: string;
    brand_id: number;
    brand: string;
    seller_sku: string;
    description: string | null;
    parcels: number;
};

type Component = {
    item_id: string;
    units: number;
};

type Listing = {
    id: number;
    marketplace: string | null;
    marketplace_id: number;
    brand: string | null;
    brand_id: number;
    seller_sku: string;
    item_id: number;
    item: string | null;
    item_code: string | null;
    units_per_order: number;
    is_active: boolean;
    components: {
        item_id: number;
        item: string | null;
        item_code: string | null;
        units: number;
    }[];
};

/** "Pack of 2", "PO2", "(2)", "x2" on the label: two pieces per order. */
function guessPieces(text: string): number {
    return /po?2|pack of 2|\(2\)|x ?2/i.test(text) ? 2 : 1;
}

/**
 * The products one order of an SKU holds. One row for a plain product or
 * a "pack of 2" (one product, two pieces); several rows for a combo.
 */
function ComponentsEditor({
    value,
    onChange,
    products,
    errors,
}: {
    value: Component[];
    onChange: (next: Component[]) => void;
    products: SearchableOption[];
    errors: Record<string, string>;
}) {
    const set = (i: number, patch: Partial<Component>) =>
        onChange(value.map((c, j) => (j === i ? { ...c, ...patch } : c)));

    return (
        <div className="space-y-2">
            {value.map((c, i) => (
                <div
                    key={i}
                    className="grid grid-cols-[1fr_5.5rem_auto] items-start gap-2"
                >
                    <div>
                        <SearchableSelect
                            value={c.item_id}
                            onValueChange={(v) => set(i, { item_id: v })}
                            options={products}
                            placeholder={
                                i === 0
                                    ? 'Which product is it?'
                                    : 'And which other product?'
                            }
                        />
                        <InputError
                            message={errors[`components.${i}.item_id`]}
                        />
                    </div>
                    <div>
                        <Input
                            type="number"
                            min={1}
                            max={100}
                            value={c.units}
                            onChange={(e) =>
                                set(i, {
                                    units: Math.max(
                                        1,
                                        Number(e.target.value) || 1,
                                    ),
                                })
                            }
                            aria-label="Pieces per order"
                            title="Pieces of this product in one order: 2 for a pack of two"
                        />
                        <p className="text-muted-foreground mt-1 text-xs">
                            pieces
                        </p>
                    </div>
                    {value.length > 1 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Remove this product"
                            onClick={() =>
                                onChange(value.filter((_, j) => j !== i))
                            }
                        >
                            <X className="size-4" />
                        </Button>
                    ) : (
                        <span className="size-9" />
                    )}
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => onChange([...value, { item_id: '', units: 1 }])}
            >
                <Plus className="size-4" />
                Add another product (combo)
            </Button>
            <InputError message={errors.components} />
        </div>
    );
}

function Contents({ components }: { components: Listing['components'] }) {
    return (
        <div className="space-y-1">
            {components.map((c) => (
                <div key={c.item_id}>
                    <span className="font-medium">{c.item}</span>
                    {c.units > 1 && (
                        <span className="ml-1 rounded bg-amber-500/15 px-1.5 text-xs font-semibold text-amber-800 dark:text-amber-200">
                            {c.units} pieces
                        </span>
                    )}
                    <div className="text-muted-foreground font-mono text-xs">
                        {c.item_code}
                    </div>
                </div>
            ))}
            {components.length > 1 && (
                <StatusBadge variant="info">
                    Combo · {components.length} products
                </StatusBadge>
            )}
        </div>
    );
}

function MapRow({
    row,
    products,
}: {
    row: Waiting;
    products: SearchableOption[];
}) {
    const form = useForm<{
        marketplace_id: number;
        brand_id: number;
        seller_sku: string;
        components: Component[];
    }>({
        marketplace_id: row.marketplace_id,
        brand_id: row.brand_id,
        seller_sku: row.seller_sku,
        components: [
            {
                item_id: '',
                units: guessPieces(
                    `${row.seller_sku} ${row.description ?? ''}`,
                ),
            },
        ],
    });

    return (
        <li className="grid gap-3 px-4 py-4 md:grid-cols-[1fr_1.6fr_auto] md:items-start">
            <div>
                <div className="font-mono font-medium">{row.seller_sku}</div>
                <div className="text-muted-foreground text-xs">
                    {row.brand} · {row.marketplace} · {row.parcels} parcel(s)
                    waiting
                </div>
                {row.description && (
                    <div className="text-muted-foreground mt-1 text-xs">
                        {row.description}
                    </div>
                )}
            </div>
            <ComponentsEditor
                value={form.data.components}
                onChange={(next) => form.setData('components', next)}
                products={products}
                errors={form.errors as Record<string, string>}
            />
            <Button
                disabled={
                    form.data.components.some((c) => c.item_id === '') ||
                    form.processing
                }
                onClick={() => form.post(store().url, { preserveScroll: true })}
            >
                Map
            </Button>
        </li>
    );
}

function ListingRow({
    listing,
    products,
}: {
    listing: Listing;
    products: SearchableOption[];
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm<{ components: Component[]; is_active: boolean }>({
        components: listing.components.map((c) => ({
            item_id: String(c.item_id),
            units: c.units,
        })),
        is_active: listing.is_active,
    });

    return (
        <tr className={cn('align-top', editing && 'bg-muted/30')}>
            <td className="px-4 py-3 font-mono">{listing.seller_sku}</td>
            <td className="px-4 py-3">
                {listing.brand}
                <div className="text-muted-foreground text-xs">
                    {listing.marketplace}
                </div>
            </td>
            <td className="px-4 py-3">
                {editing ? (
                    <ComponentsEditor
                        value={form.data.components}
                        onChange={(next) => form.setData('components', next)}
                        products={products}
                        errors={form.errors as Record<string, string>}
                    />
                ) : (
                    <Contents components={listing.components} />
                )}
            </td>
            <td className="px-4 py-3">
                {editing ? (
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.is_active}
                            onChange={(e) =>
                                form.setData('is_active', e.target.checked)
                            }
                        />
                        Matched
                    </label>
                ) : (
                    <StatusBadge
                        variant={listing.is_active ? 'success' : 'muted'}
                    >
                        {listing.is_active ? 'Matched' : 'Off'}
                    </StatusBadge>
                )}
            </td>
            <td className="px-4 py-3 text-right whitespace-nowrap">
                {editing ? (
                    <>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditing(false)}
                        >
                            Back
                        </Button>
                        <Button
                            size="sm"
                            disabled={
                                form.processing ||
                                (form.data.is_active &&
                                    form.data.components.some(
                                        (c) => c.item_id === '',
                                    ))
                            }
                            onClick={() =>
                                form.patch(update(listing.id).url, {
                                    preserveScroll: true,
                                    onSuccess: () => setEditing(false),
                                })
                            }
                        >
                            Save
                        </Button>
                    </>
                ) : (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        Change
                    </Button>
                )}
            </td>
        </tr>
    );
}

export default function SkuMapping({
    waiting,
    listings,
    products,
}: {
    waiting: Waiting[];
    listings: Listing[];
    products: SearchableOption[];
    marketplaces: { value: string; label: string }[];
    brands: { value: string; label: string }[];
}) {
    const [filter, setFilter] = useState('');
    const shown = listings.filter((l) =>
        `${l.seller_sku} ${l.components.map((c) => c.item ?? '').join(' ')} ${l.brand ?? ''} ${l.marketplace ?? ''}`
            .toLowerCase()
            .includes(filter.toLowerCase()),
    );

    return (
        <>
            <Head title="SKU mapping" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="SKU mapping"
                    description="What each marketplace prints, and what goes in the box for it: one product, a pack of two (one product, 2 pieces), or a combo of several products. Map an SKU once; every label after carries it straight to the right stock."
                />

                <section className="bg-card rounded-xl border">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <Tags className="size-4" />
                            Waiting to be mapped
                        </h2>
                        <span className="text-muted-foreground text-xs">
                            Parcels with these SKUs cannot be packed yet
                        </span>
                    </div>
                    {waiting.length === 0 ? (
                        <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                            Every SKU on today&rsquo;s labels is mapped.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {waiting.map((w) => (
                                <MapRow
                                    key={`${w.marketplace_id}-${w.brand_id}-${w.seller_sku}`}
                                    row={w}
                                    products={products}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            Mapped ({listings.length})
                        </h2>
                        <Input
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder="Find an SKU or product…"
                            className="max-w-xs"
                        />
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs uppercase">
                                <tr className="border-b">
                                    <th className="px-4 py-2 font-medium">
                                        SKU as printed
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        Brand · marketplace
                                    </th>
                                    <th className="px-4 py-2 font-medium">
                                        What goes in the box
                                    </th>
                                    <th className="px-4 py-2 font-medium" />
                                    <th className="px-4 py-2" />
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {shown.map((l) => (
                                    <ListingRow
                                        key={l.id}
                                        listing={l}
                                        products={products}
                                    />
                                ))}
                            </tbody>
                        </table>
                        {shown.length === 0 && (
                            <p className="text-muted-foreground px-4 py-6 text-center text-sm">
                                Nothing mapped yet.
                            </p>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}
