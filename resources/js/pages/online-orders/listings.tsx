import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Boxes, Plus, Tags, X } from 'lucide-react';
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
import { packs, store, update } from '@/routes/listings';

type Waiting = {
    marketplace_id: number;
    marketplace: string;
    brand_id: number;
    brand: string;
    seller_sku: string;
    description: string | null;
    parcels: number;
    /** The SKU and its title in plain words. */
    reading: { name: string | null; size: string | null; pack: number | null };
    /** The ERP product it most likely is, picked in advance to confirm. */
    suggested: { item_id: string; pieces: number } | null;
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
    /** More than one piece on an SKU that is not a pack: likely an order quantity typed as pieces. */
    suspect: boolean;
    components: {
        item_id: number;
        item: string | null;
        item_code: string | null;
        units: number;
    }[];
};

type PackMove = {
    item_id: number;
    item: string | null;
    item_code: string | null;
    quantity: number;
    pack: string | null;
    listings: {
        id: number;
        marketplace: string | null;
        brand: string | null;
        seller_sku: string;
    }[];
};

/**
 * Listings sold as two of one product, to be taken from that product's
 * Pack of 2 stock instead.
 */
function PackMoves({ moves }: { moves: PackMove[] }) {
    const [busy, setBusy] = useState<number | null>(null);

    if (moves.length === 0) {
        return null;
    }

    const move = (m: PackMove) => {
        if (
            !window.confirm(
                `Take ${m.listings.length} listing(s) of ${m.quantity} × ${m.item} from the "${m.item} (Pack of ${m.quantity})" stock? ${m.pack ? '' : 'The Pack of ' + m.quantity + ' product is added now. '}Waiting parcels are matched again at once.`,
            )
        ) {
            return;
        }

        setBusy(m.item_id);
        router.post(
            packs().url,
            { item_id: m.item_id, quantity: m.quantity },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Boxes className="size-4" />
                    Sold as packs, taken as single pieces
                </h2>
                <span className="text-muted-foreground text-xs">
                    Switch them to the Pack of 2 product once its stock is kept
                    as packs
                </span>
            </div>
            <ul className="divide-y">
                {moves.map((m) => (
                    <li
                        key={`${m.item_id}-${m.quantity}`}
                        className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <div className="min-w-0">
                            <div className="font-medium">
                                {m.quantity} × {m.item}{' '}
                                <span className="text-muted-foreground font-mono text-xs">
                                    {m.item_code}
                                </span>
                            </div>
                            <div className="text-muted-foreground text-xs">
                                {m.listings
                                    .map(
                                        (l) =>
                                            `${l.seller_sku} (${l.brand} · ${l.marketplace})`,
                                    )
                                    .join(', ')}
                            </div>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy !== null}
                            onClick={() => move(m)}
                        >
                            {busy === m.item_id
                                ? 'Switching…'
                                : m.pack
                                  ? `Take from ${m.pack}`
                                  : `Take from Pack of ${m.quantity}`}
                        </Button>
                    </li>
                ))}
            </ul>
        </section>
    );
}

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
                            aria-label="Pieces in one unit of this SKU"
                            title="Pieces in ONE unit of this SKU: 1 for a single bottle, 2 only if the SKU itself is a pack of two. Not the quantity ordered: that is read from each label."
                        />
                        <p className="text-muted-foreground mt-1 text-xs leading-tight">
                            pieces in 1 unit
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
            <p className="text-muted-foreground text-xs">
                Pieces is what <strong>one unit</strong> of this SKU holds: 1
                for a single bottle, 2 only when the SKU itself is a pack of
                two. Do not enter the quantity ordered: the ERP reads that from
                each label and multiplies.
            </p>
        </div>
    );
}

/**
 * Before saving more than one piece on an SKU that does not read as a
 * pack: is it the SKU that holds that many, or was it one order's quantity?
 */
function sureOfPieces(sku: string, components: Component[]): boolean {
    const most = Math.max(...components.map((c) => c.units));

    if (components.length !== 1 || most <= 1 || guessPieces(sku) === most) {
        return true;
    }

    return window.confirm(
        `Is ONE unit of "${sku}" really ${most} pieces?\n\nIf a customer ordered ${most}, the label already says quantity ${most} and the ERP multiplies by it. A single bottle should be 1 piece.\n\nOK = this SKU is a pack of ${most}. Cancel = go back and set 1.`,
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
                item_id: row.suggested?.item_id ?? '',
                units:
                    row.suggested?.pieces ??
                    row.reading.pack ??
                    guessPieces(`${row.seller_sku} ${row.description ?? ''}`),
            },
        ],
    });
    const suggested = row.suggested
        ? products.find((p) => p.value === row.suggested?.item_id)
        : undefined;

    return (
        <li className="grid gap-3 px-4 py-4 md:grid-cols-[1fr_1.6fr_auto] md:items-start">
            <div>
                <div className="font-mono font-medium">{row.seller_sku}</div>
                <div className="text-muted-foreground text-xs">
                    {row.brand} · {row.marketplace} · {row.parcels} parcel(s)
                    waiting
                </div>
                {(row.reading.name || row.reading.size) && (
                    <div className="mt-2 flex flex-wrap items-center gap-1.5 text-sm">
                        <span className="text-muted-foreground text-xs">
                            Reads as
                        </span>
                        {row.reading.name && (
                            <span className="font-semibold">
                                {row.reading.name}
                            </span>
                        )}
                        {row.reading.size && (
                            <span className="rounded bg-sky-500/15 px-1.5 text-xs font-semibold text-sky-800 dark:text-sky-200">
                                {row.reading.size}
                            </span>
                        )}
                        {row.reading.pack && (
                            <span className="rounded bg-amber-500/15 px-1.5 text-xs font-semibold text-amber-800 dark:text-amber-200">
                                Pack of {row.reading.pack}
                            </span>
                        )}
                        {!row.reading.size && (
                            <span className="text-xs text-amber-700 dark:text-amber-300">
                                size not printed: check the parcel
                            </span>
                        )}
                    </div>
                )}
                {suggested ? (
                    <div className="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                        Suggested: {suggested.label}
                        {suggested.hint ? ` (${suggested.hint})` : ''}
                        {(row.suggested?.pieces ?? 1) > 1
                            ? ` × ${row.suggested?.pieces}`
                            : ''}{' '}
                        — check it, then Map
                    </div>
                ) : (
                    <div className="text-muted-foreground mt-1 text-xs">
                        No clear match: choose the product.
                    </div>
                )}
                {row.description && (
                    <details className="text-muted-foreground mt-1 text-xs">
                        <summary className="cursor-pointer">
                            As printed on the label
                        </summary>
                        {row.description}
                    </details>
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
                onClick={() =>
                    sureOfPieces(
                        `${row.seller_sku} ${row.description ?? ''}`,
                        form.data.components,
                    ) && form.post(store().url, { preserveScroll: true })
                }
            >
                Map
            </Button>
        </li>
    );
}

/**
 * Mappings that take several pieces for one unit of an SKU that is not a
 * pack: every label of that SKU is being multiplied twice. One click sets
 * each back to one piece and matches the waiting parcels again.
 */
function SuspectMappings({ listings }: { listings: Listing[] }) {
    const suspects = listings.filter((l) => l.suspect);
    const [busy, setBusy] = useState<number | null>(null);

    if (suspects.length === 0) {
        return null;
    }

    const setToOne = (l: Listing) => {
        setBusy(l.id);
        router.patch(
            update(l.id).url,
            {
                is_active: true,
                components: [
                    { item_id: String(l.components[0].item_id), units: 1 },
                ],
            },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <section className="rounded-xl border border-red-500/50 bg-red-500/5">
            <div className="border-b border-red-500/30 px-4 py-3">
                <h2 className="flex items-center gap-2 text-sm font-semibold text-red-700 dark:text-red-300">
                    <AlertTriangle className="size-4" />
                    {suspects.length} mapping{suspects.length === 1 ? '' : 's'}{' '}
                    to check: more than 1 piece on an SKU that is not a pack
                </h2>
                <p className="mt-1 text-xs">
                    The ERP multiplies the label&rsquo;s quantity by these
                    pieces. A label for 4 of an SKU mapped as 4 pieces asks for
                    16. If the SKU is one bottle, set it to 1 piece.
                </p>
            </div>
            <ul className="divide-y divide-red-500/20">
                {suspects.map((l) => (
                    <li
                        key={l.id}
                        className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                    >
                        <div className="min-w-0 flex-1">
                            <span className="font-mono font-medium">
                                {l.seller_sku}
                            </span>
                            <span className="text-muted-foreground ml-2 text-xs">
                                {l.brand} · {l.marketplace}
                            </span>
                            <div className="mt-0.5">
                                Now:{' '}
                                <strong>{l.components[0].units} pieces</strong>{' '}
                                of {l.components[0].item} for each unit ordered
                            </div>
                        </div>
                        <Button
                            size="sm"
                            disabled={busy !== null}
                            onClick={() => setToOne(l)}
                        >
                            {busy === l.id ? 'Setting…' : 'Set to 1 piece'}
                        </Button>
                    </li>
                ))}
            </ul>
            <p className="text-muted-foreground border-t border-red-500/20 px-4 py-2 text-xs">
                If one of these really is a pack of that many, leave it: it
                stays listed here only as a reminder.
            </p>
        </section>
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
                    <>
                        <Contents components={listing.components} />
                        {listing.suspect && (
                            <p className="mt-1 inline-flex items-center gap-1 rounded-full bg-red-500/15 px-2 py-0.5 text-xs font-semibold text-red-700 dark:text-red-300">
                                <AlertTriangle className="size-3.5" />
                                Check: this SKU is not a pack
                            </p>
                        )}
                    </>
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
                                (!form.data.is_active ||
                                    sureOfPieces(
                                        listing.seller_sku,
                                        form.data.components,
                                    )) &&
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
    pack_moves: packMoves,
    products,
}: {
    waiting: Waiting[];
    listings: Listing[];
    pack_moves: PackMove[];
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
                    description="What each marketplace prints, and what ONE unit of it is: one product, a pack of two (one product, 2 pieces), or a combo of several products. The quantity a customer ordered is read from each label and multiplied, so it is never typed here. Map an SKU once; every label after carries it straight to the right stock."
                />

                <SuspectMappings listings={listings} />

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

                <PackMoves moves={packMoves} />

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
