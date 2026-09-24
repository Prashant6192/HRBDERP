import { Head, useForm } from '@inertiajs/react';
import { Tags } from 'lucide-react';
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
};

function MapRow({
    row,
    products,
}: {
    row: Waiting;
    products: SearchableOption[];
}) {
    const form = useForm({
        marketplace_id: row.marketplace_id,
        brand_id: row.brand_id,
        seller_sku: row.seller_sku,
        item_id: '',
        units_per_order: /po?2|pack of 2|\(2\)|x ?2/i.test(
            `${row.seller_sku} ${row.description ?? ''}`,
        )
            ? 2
            : 1,
    });

    return (
        <li className="grid gap-3 px-4 py-4 md:grid-cols-[1fr_1.2fr_7rem_auto] md:items-start">
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
            <div>
                <SearchableSelect
                    value={form.data.item_id}
                    onValueChange={(v) => form.setData('item_id', v)}
                    options={products}
                    placeholder="Which product is it?"
                />
                <InputError message={form.errors.item_id} />
            </div>
            <div>
                <Input
                    type="number"
                    min={1}
                    max={100}
                    value={form.data.units_per_order}
                    onChange={(e) =>
                        form.setData(
                            'units_per_order',
                            Math.max(1, Number(e.target.value) || 1),
                        )
                    }
                    aria-label="Units per order"
                    title="Units per order: 2 for a pack of two"
                />
                <p className="text-muted-foreground mt-1 text-xs">
                    units per order
                </p>
            </div>
            <Button
                disabled={form.data.item_id === '' || form.processing}
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
    const form = useForm({
        item_id: String(listing.item_id),
        units_per_order: listing.units_per_order,
        is_active: listing.is_active,
    });

    if (!editing) {
        return (
            <tr className="align-top">
                <td className="px-4 py-3 font-mono">{listing.seller_sku}</td>
                <td className="px-4 py-3">
                    {listing.brand}
                    <div className="text-muted-foreground text-xs">
                        {listing.marketplace}
                    </div>
                </td>
                <td className="px-4 py-3">
                    {listing.item}
                    <div className="text-muted-foreground font-mono text-xs">
                        {listing.item_code}
                    </div>
                </td>
                <td className="px-4 py-3 text-right tabular-nums">
                    × {listing.units_per_order}
                </td>
                <td className="px-4 py-3">
                    <StatusBadge
                        variant={listing.is_active ? 'success' : 'muted'}
                    >
                        {listing.is_active ? 'Matched' : 'Off'}
                    </StatusBadge>
                </td>
                <td className="px-4 py-3 text-right">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        Change
                    </Button>
                </td>
            </tr>
        );
    }

    return (
        <tr className="bg-muted/30 align-top">
            <td className="px-4 py-3 font-mono">{listing.seller_sku}</td>
            <td className="px-4 py-3">
                {listing.brand}
                <div className="text-muted-foreground text-xs">
                    {listing.marketplace}
                </div>
            </td>
            <td className="px-4 py-3">
                <SearchableSelect
                    value={form.data.item_id}
                    onValueChange={(v) => form.setData('item_id', v)}
                    options={products}
                />
            </td>
            <td className="px-4 py-3">
                <Input
                    type="number"
                    min={1}
                    value={form.data.units_per_order}
                    onChange={(e) =>
                        form.setData(
                            'units_per_order',
                            Math.max(1, Number(e.target.value) || 1),
                        )
                    }
                    className="w-20"
                    aria-label="Units per order"
                />
            </td>
            <td className="px-4 py-3">
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
            </td>
            <td className="px-4 py-3 text-right whitespace-nowrap">
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => setEditing(false)}
                >
                    Back
                </Button>
                <Button
                    size="sm"
                    disabled={form.processing}
                    onClick={() =>
                        form.patch(update(listing.id).url, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                        })
                    }
                >
                    Save
                </Button>
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
        `${l.seller_sku} ${l.item ?? ''} ${l.brand ?? ''} ${l.marketplace ?? ''}`
            .toLowerCase()
            .includes(filter.toLowerCase()),
    );

    return (
        <>
            <Head title="SKU mapping" />
            <div className="space-y-6">
                <PageHeader
                    title="SKU mapping"
                    description="What each marketplace prints for a product, and which product that is. Map an SKU once; every label after carries it straight to the right stock."
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
                                        Product
                                    </th>
                                    <th className="px-4 py-2 text-right font-medium">
                                        Units
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
