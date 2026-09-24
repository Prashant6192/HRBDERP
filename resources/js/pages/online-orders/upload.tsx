import { Head, Link, useForm } from '@inertiajs/react';
import { FileUp, Info, LoaderCircle, Upload, X } from 'lucide-react';
import { useMemo, useRef, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Batch } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { index, show, store } from '@/routes/online-orders';

type BrandOption = {
    id: number;
    name: string;
    store_id: number | null;
    store: string | null;
};

type MarketplaceOption = { id: number; name: string; reads: string };

function Choice({
    active,
    onClick,
    children,
    hint,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
    hint?: string | null;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'rounded-xl border px-4 py-3 text-left transition-colors',
                active
                    ? 'border-primary bg-primary/10 ring-primary/30 ring-2'
                    : 'hover:bg-muted/50',
            )}
        >
            <span className="block font-medium">{children}</span>
            {hint && (
                <span className="text-muted-foreground block text-xs">
                    {hint}
                </span>
            )}
        </button>
    );
}

export default function UploadLabels({
    brands,
    marketplaces,
    stores,
    can_choose_store,
    open,
}: {
    brands: BrandOption[];
    marketplaces: MarketplaceOption[];
    stores: { value: string; label: string }[];
    can_choose_store: boolean;
    today: string;
    open: (Batch & { parcels: number })[];
}) {
    const input = useRef<HTMLInputElement>(null);
    const form = useForm<{
        brand_id: number | null;
        marketplace_id: number | null;
        warehouse_id: string;
        files: File[];
    }>({
        brand_id: brands.length === 1 ? brands[0].id : null,
        marketplace_id: null,
        warehouse_id: '',
        files: [],
    });

    const brand = useMemo(
        () => brands.find((b) => b.id === form.data.brand_id) ?? null,
        [brands, form.data.brand_id],
    );

    const addFiles = (list: FileList | null) => {
        if (!list) return;
        const pdfs = Array.from(list).filter(
            (f) =>
                f.type === 'application/pdf' ||
                f.name.toLowerCase().endsWith('.pdf'),
        );
        form.setData('files', [...form.data.files, ...pdfs].slice(0, 10));
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url, { forceFormData: true });
    };

    const fileError =
        form.errors.files ??
        Object.entries(form.errors).find(([k]) => k.startsWith('files.'))?.[1];

    const ready =
        form.data.brand_id !== null &&
        form.data.marketplace_id !== null &&
        form.data.files.length > 0 &&
        (brand?.store_id !== null || form.data.warehouse_id !== '');

    return (
        <>
            <Head title="Upload labels" />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title="Upload labels"
                    description="Upload the label PDF exactly as the marketplace gave it. Every label becomes a parcel the depot prints, packs and hands to the courier."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={index()}>Back</Link>
                        </Button>
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <section className="space-y-2">
                        <Label>1. Brand</Label>
                        {brands.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No brand has been given to your account yet. Ask
                                the office to add you to a brand.
                            </p>
                        ) : (
                            <div className="grid gap-2 sm:grid-cols-2">
                                {brands.map((b) => (
                                    <Choice
                                        key={b.id}
                                        active={form.data.brand_id === b.id}
                                        onClick={() =>
                                            form.setData('brand_id', b.id)
                                        }
                                        hint={
                                            b.store
                                                ? `Ships from ${b.store}`
                                                : 'Store not set yet'
                                        }
                                    >
                                        {b.name}
                                    </Choice>
                                ))}
                            </div>
                        )}
                        <InputError message={form.errors.brand_id} />
                    </section>

                    <section className="space-y-2">
                        <Label>2. Marketplace</Label>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {marketplaces.map((m) => (
                                <Choice
                                    key={m.id}
                                    active={form.data.marketplace_id === m.id}
                                    onClick={() =>
                                        form.setData('marketplace_id', m.id)
                                    }
                                    hint={m.reads}
                                >
                                    {m.name}
                                </Choice>
                            ))}
                        </div>
                        <InputError message={form.errors.marketplace_id} />
                    </section>

                    {can_choose_store && (
                        <section className="space-y-2">
                            <Label htmlFor="warehouse_id">
                                Ships from (only if not the brand&rsquo;s own
                                store)
                            </Label>
                            <select
                                id="warehouse_id"
                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
                                value={form.data.warehouse_id}
                                onChange={(e) =>
                                    form.setData('warehouse_id', e.target.value)
                                }
                            >
                                <option value="">
                                    {brand?.store
                                        ? `The brand's store: ${brand.store}`
                                        : 'Choose a store'}
                                </option>
                                {stores.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.warehouse_id} />
                        </section>
                    )}

                    <section className="space-y-2">
                        <Label>3. Label files</Label>
                        <div
                            className="hover:bg-muted/40 flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-4 py-10 text-center"
                            onClick={() => input.current?.click()}
                            onDragOver={(e) => e.preventDefault()}
                            onDrop={(e) => {
                                e.preventDefault();
                                addFiles(e.dataTransfer.files);
                            }}
                        >
                            <FileUp className="text-muted-foreground size-8" />
                            <p className="font-medium">
                                Drop the PDFs here, or tap to choose
                            </p>
                            <p className="text-muted-foreground text-xs">
                                Up to 10 files, 20 MB each. Labels already
                                uploaded are skipped, never doubled.
                            </p>
                            <input
                                ref={input}
                                type="file"
                                accept="application/pdf,.pdf"
                                multiple
                                className="hidden"
                                onChange={(e) => {
                                    addFiles(e.target.files);
                                    e.target.value = '';
                                }}
                            />
                        </div>
                        {form.data.files.length > 0 && (
                            <ul className="divide-y rounded-lg border text-sm">
                                {form.data.files.map((f, i) => (
                                    <li
                                        key={`${f.name}-${i}`}
                                        className="flex items-center gap-3 px-3 py-2"
                                    >
                                        <span className="flex-1 truncate">
                                            {f.name}
                                        </span>
                                        <span className="text-muted-foreground text-xs tabular-nums">
                                            {(f.size / 1024).toFixed(0)} KB
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Remove ${f.name}`}
                                            onClick={() =>
                                                form.setData(
                                                    'files',
                                                    form.data.files.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-4" />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <InputError message={fileError} />
                    </section>

                    <div className="bg-muted/40 text-muted-foreground flex gap-2 rounded-lg p-3 text-xs">
                        <Info className="size-4 shrink-0" />
                        <span>
                            Upload through the morning as the marketplaces
                            release labels; each file joins today&rsquo;s batch
                            for the brand. When the last file is in, open the
                            batch and press <b>That&rsquo;s all for today</b> so
                            the depot knows to print.
                        </span>
                    </div>

                    <Button
                        type="submit"
                        className="h-11 w-full"
                        disabled={!ready || form.processing}
                    >
                        {form.processing ? (
                            <>
                                <LoaderCircle className="size-4 animate-spin" />
                                Reading the labels…
                            </>
                        ) : (
                            <>
                                <Upload className="size-4" />
                                Upload {form.data.files.length || ''} file
                                {form.data.files.length === 1 ? '' : 's'}
                            </>
                        )}
                    </Button>
                </form>

                {open.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Still open
                        </h2>
                        <ul className="divide-y text-sm">
                            {open.map((b) => (
                                <li key={b.id}>
                                    <Link
                                        href={show(b.id)}
                                        className="hover:bg-muted/40 flex items-center gap-3 px-4 py-3"
                                    >
                                        <span className="font-mono font-medium">
                                            {b.number}
                                        </span>
                                        <span>
                                            {b.brand} · {b.marketplace}
                                        </span>
                                        <span className="text-muted-foreground ml-auto">
                                            {b.parcels} parcel(s)
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
