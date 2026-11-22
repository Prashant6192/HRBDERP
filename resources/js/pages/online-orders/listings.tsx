import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Plus, Tags, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { LabelPreview } from '@/components/online-orders/label-preview';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    SearchableSelect,
    type SearchableOption,
} from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import { destroy, store, update } from '@/routes/listings';

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
    /** The parcels this SKU is on, newest first, each with its label. */
    orders: {
        shipment_id: number;
        order_number: string | null;
        awb: string | null;
        quantity: number;
        for_date: string | null;
        file_url: string | null;
        page: number;
    }[];
};

/** A product to choose from: with its photo for the tiles. */
type ProductOption = SearchableOption & {
    name: string;
    size: string | null;
    is_pack: boolean;
    photo: string | null;
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

/** What one unit of an SKU is: a single, a pack of two, or something else. */
type Kind = 'single' | 'pack2' | 'other';

/**
 * One waiting SKU at a time, as two questions: which product (its photo),
 * and whether one unit is a single, a pack of two or a bundle. The orders
 * it is on are listed, each with its label to look at.
 */
function MapWizard({
    waiting,
    products,
}: {
    waiting: Waiting[];
    products: ProductOption[];
}) {
    const [at, setAt] = useState(0);
    const index = Math.min(at, waiting.length - 1);
    const row = waiting[index];

    return (
        <div className="p-4">
            <div className="mb-4 flex items-center gap-3">
                <div className="flex flex-1 gap-1.5">
                    {waiting.map((w, i) => (
                        <button
                            key={`${w.marketplace_id}-${w.brand_id}-${w.seller_sku}`}
                            type="button"
                            aria-label={`SKU ${i + 1}: ${w.seller_sku}`}
                            title={w.seller_sku}
                            onClick={() => setAt(i)}
                            className={cn(
                                'h-2 flex-1 rounded-full',
                                i === index ? 'bg-primary' : 'bg-muted',
                            )}
                        />
                    ))}
                </div>
                <span className="text-muted-foreground text-xs font-semibold whitespace-nowrap">
                    SKU {index + 1} of {waiting.length} to map
                </span>
            </div>
            <WizardCard
                key={`${row.marketplace_id}-${row.brand_id}-${row.seller_sku}`}
                row={row}
                products={products}
                onSkip={
                    waiting.length > 1
                        ? () => setAt((index + 1) % waiting.length)
                        : undefined
                }
            />
        </div>
    );
}

function WizardCard({
    row,
    products,
    onSkip,
}: {
    row: Waiting;
    products: ProductOption[];
    onSkip?: () => void;
}) {
    const singles = products.filter((p) => !p.is_pack);
    const suggested = singles.find((p) => p.value === row.suggested?.item_id);
    const said = row.suggested?.pieces ?? row.reading.pack ?? 1;

    const [itemId, setItemId] = useState(suggested?.value ?? '');
    const [kind, setKind] = useState<Kind>(
        said === 1 ? 'single' : said === 2 ? 'pack2' : 'other',
    );
    const [parts, setParts] = useState<Component[]>([
        { item_id: suggested?.value ?? '', units: said },
    ]);
    const [find, setFind] = useState('');
    // An SKU that is only a number, or names no size, says too little:
    // its first label is opened straight away to read the product off it.
    const [label, setLabel] = useState<number | null>(
        (/^\d+$/.test(row.seller_sku.trim()) || !row.reading.size) &&
            row.orders[0]?.file_url
            ? row.orders[0].shipment_id
            : null,
    );
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const chosen = singles.find((p) => p.value === itemId);
    const components: Component[] =
        kind === 'other'
            ? parts
            : [{ item_id: itemId, units: kind === 'pack2' ? 2 : 1 }];
    const ready = components.every((c) => c.item_id !== '');
    const example = row.orders[0]?.quantity ?? 1;
    const shown = singles.filter((p) =>
        `${p.label} ${p.hint ?? ''}`.toLowerCase().includes(find.toLowerCase()),
    );
    const viewing = row.orders.find((o) => o.shipment_id === label);

    const save = () => {
        if (
            kind === 'other' &&
            !sureOfPieces(
                `${row.seller_sku} ${row.description ?? ''}`,
                components,
            )
        ) {
            return;
        }

        setBusy(true);
        router.post(
            store().url,
            {
                marketplace_id: row.marketplace_id,
                brand_id: row.brand_id,
                seller_sku: row.seller_sku,
                components,
            },
            {
                preserveScroll: true,
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
            <div className="space-y-3">
                <div className="bg-background rounded-xl border p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="bg-foreground text-background rounded-lg px-2.5 py-0.5 text-sm font-bold">
                            {row.marketplace}
                        </span>
                        <span className="text-muted-foreground text-xs">
                            {row.brand} · {row.parcels}{' '}
                            {row.parcels === 1 ? 'parcel' : 'parcels'} waiting
                        </span>
                    </div>
                    <p className="mt-2 font-mono text-lg font-semibold break-all">
                        {row.seller_sku}
                    </p>
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
                                    size not printed: look at the label
                                </span>
                            )}
                        </div>
                    )}
                    {row.description && (
                        <p className="text-muted-foreground bg-muted/40 mt-3 rounded-lg border border-dashed px-3 py-2 text-xs">
                            On the label: {row.description}
                        </p>
                    )}
                </div>

                <div className="bg-background rounded-xl border">
                    <p className="text-muted-foreground border-b px-4 py-2 text-xs font-semibold tracking-wide uppercase">
                        Orders with this SKU
                    </p>
                    {row.orders.length === 0 ? (
                        <p className="text-muted-foreground px-4 py-3 text-sm">
                            No order found.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {row.orders.map((o) => (
                                <li
                                    key={o.shipment_id}
                                    className="flex items-center gap-2 px-4 py-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-mono text-xs font-semibold break-all">
                                            {o.order_number ?? o.awb ?? '—'}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            Quantity {o.quantity}
                                            {o.for_date
                                                ? ` · ${new Date(`${o.for_date}T00:00:00`).toLocaleDateString('en-IN', { day: 'numeric', month: 'short' })}`
                                                : ''}
                                            {o.awb && o.order_number
                                                ? ` · ${o.awb}`
                                                : ''}
                                        </p>
                                    </div>
                                    {o.file_url && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                label === o.shipment_id
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                setLabel(
                                                    label === o.shipment_id
                                                        ? null
                                                        : o.shipment_id,
                                                )
                                            }
                                        >
                                            {label === o.shipment_id
                                                ? 'Hide label'
                                                : 'View label'}
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {row.parcels > row.orders.length && (
                        <p className="text-muted-foreground border-t px-4 py-2 text-xs">
                            Showing the newest {row.orders.length} of{' '}
                            {row.parcels}.
                        </p>
                    )}
                </div>

                {viewing?.file_url && (
                    <LabelPreview
                        fileUrl={viewing.file_url}
                        page={viewing.page}
                    />
                )}
            </div>

            <div className="space-y-5">
                <div>
                    <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                            1 · One unit of this SKU is
                        </p>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-3">
                        {(
                            [
                                ['single', 'Single', 'one bottle or tube'],
                                [
                                    'pack2',
                                    'Pack of 2',
                                    'two of the same product',
                                ],
                                [
                                    'other',
                                    'Bundle or bigger pack',
                                    'different products, or 3 and more',
                                ],
                            ] as [Kind, string, string][]
                        ).map(([value, title, hint]) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={kind === value}
                                onClick={() => setKind(value)}
                                className={cn(
                                    'bg-background rounded-xl border-2 p-3 text-left transition',
                                    kind === value &&
                                        'border-primary ring-primary/20 ring-4',
                                )}
                            >
                                <span className="block font-semibold">
                                    {title}
                                </span>
                                <span className="text-muted-foreground block text-xs">
                                    {hint}
                                </span>
                            </button>
                        ))}
                    </div>
                </div>

                {kind === 'other' ? (
                    <div>
                        <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
                            2 · What one unit holds
                        </p>
                        <ComponentsEditor
                            value={parts}
                            onChange={setParts}
                            products={products}
                            errors={errors}
                        />
                    </div>
                ) : (
                    <div>
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                                2 · Which product is it?
                            </p>
                            {singles.length > 10 && (
                                <Input
                                    value={find}
                                    onChange={(e) => setFind(e.target.value)}
                                    placeholder="Find a product…"
                                    className="h-8 max-w-52"
                                />
                            )}
                        </div>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-5">
                            {shown.map((p) => (
                                <button
                                    key={p.value}
                                    type="button"
                                    aria-pressed={itemId === p.value}
                                    onClick={() => setItemId(p.value)}
                                    className={cn(
                                        'bg-background relative rounded-xl border-2 p-2 text-left transition',
                                        itemId === p.value &&
                                            'border-primary ring-primary/20 ring-4',
                                    )}
                                >
                                    {p.photo ? (
                                        <img
                                            src={p.photo}
                                            alt=""
                                            className="aspect-square w-full rounded-lg bg-white object-contain"
                                        />
                                    ) : (
                                        <span className="bg-muted text-muted-foreground flex aspect-square w-full items-center justify-center rounded-lg text-xs">
                                            No photo
                                        </span>
                                    )}
                                    {suggested?.value === p.value && (
                                        <span className="absolute top-1.5 left-1.5 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold text-white">
                                            Suggested
                                        </span>
                                    )}
                                    <span className="mt-1.5 block text-sm leading-tight font-semibold">
                                        {p.name}
                                    </span>
                                    {p.size && (
                                        <span className="text-primary block text-xs font-bold">
                                            {p.size}
                                        </span>
                                    )}
                                </button>
                            ))}
                        </div>
                        <InputError message={errors['components.0.item_id']} />
                    </div>
                )}

                <p
                    className={cn(
                        'rounded-xl px-4 py-3 text-sm font-semibold',
                        ready
                            ? 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-200'
                            : 'bg-muted text-muted-foreground font-normal',
                    )}
                >
                    {kind === 'other'
                        ? ready
                            ? `One "${row.seller_sku}" holds the products above. A label's quantity multiplies all of them.`
                            : 'Choose every product in the bundle.'
                        : chosen
                          ? `One "${row.seller_sku}" = ${kind === 'pack2' ? 2 : 1} × ${chosen.label}. ${row.orders[0] ? 'This order' : 'A label'} with quantity ${example} asks the packer for ${example * (kind === 'pack2' ? 2 : 1)}.`
                          : 'Pick the product above.'}
                </p>

                <div className="flex flex-wrap gap-2">
                    <Button size="lg" disabled={!ready || busy} onClick={save}>
                        {busy ? 'Mapping…' : 'Map and next'}
                    </Button>
                    {onSkip && (
                        <Button
                            size="lg"
                            variant="outline"
                            disabled={busy}
                            onClick={onSkip}
                        >
                            Skip for now
                        </Button>
                    )}
                </div>
            </div>
        </div>
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
                    <>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setEditing(true)}
                        >
                            Change
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-red-700 hover:text-red-800 dark:text-red-300"
                            onClick={() =>
                                window.confirm(
                                    `Delete the mapping of "${listing.seller_sku}"?\n\nParcels of this SKU still waiting to be packed go back to "Waiting to be mapped" and their stock goes back on the shelf. Parcels already packed are not changed.`,
                                ) &&
                                router.delete(destroy(listing.id).url, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Delete
                        </Button>
                    </>
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
    products: ProductOption[];
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
                        <MapWizard waiting={waiting} products={products} />
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
