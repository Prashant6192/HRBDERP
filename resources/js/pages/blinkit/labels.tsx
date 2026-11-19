import { Head, useForm } from '@inertiajs/react';
import { Plus, Printer } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    index as labelsIndex,
    print as printRoute,
    store,
    update,
} from '@/routes/blinkit-labels';

type Product = {
    id: number;
    brand_name: string;
    product_name: string;
    upc: string;
    pid: string | null;
    mrp: string;
    uom: string | null;
    units_in_box: number | null;
    is_active: boolean;
    bars: [number, number][] | null;
};

type Kind = 'sku' | 'box';

const blank = {
    brand_name: 'Rahat Rooh',
    product_name: '',
    upc: '',
    pid: '',
    mrp: '',
    uom: '',
    units_in_box: '',
    is_active: true,
};

function monthLabel(v: string): string {
    if (!v) {
        return '—';
    }

    const [y, m] = v.split('-').map(Number);

    return new Date(y, m - 1, 1)
        .toLocaleString('en-IN', { month: 'short', year: 'numeric' })
        .toUpperCase();
}

function thisMonth(add = 0): string {
    const d = new Date();
    d.setMonth(d.getMonth() + add);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

/** The barcode as drawn on the label: bars as shares of its width. */
function Bars({
    bars,
    height,
}: {
    bars: [number, number][] | null;
    height: string;
}) {
    if (!bars) {
        return (
            <div
                className="flex items-center justify-center text-[2mm] text-red-700"
                style={{ height }}
            >
                This UPC cannot be a barcode
            </div>
        );
    }

    return (
        <svg
            viewBox="0 0 100 10"
            preserveAspectRatio="none"
            className="block w-full"
            style={{ height }}
            aria-hidden="true"
        >
            {bars.map(([x, w], i) => (
                <rect key={i} x={x} y={0} width={w} height={10} fill="#000" />
            ))}
        </svg>
    );
}

function SkuLabel({ p, mfg, exp }: { p: Product; mfg: string; exp: string }) {
    return (
        <div
            className="relative shrink-0 overflow-hidden bg-white text-black"
            style={{
                width: '50mm',
                height: '25mm',
                padding: '1.2mm 2.5mm',
                fontFamily: 'Arial, Helvetica, sans-serif',
            }}
        >
            <div
                className="flex justify-between font-bold"
                style={{ fontSize: '2.25mm' }}
            >
                <span>{p.brand_name}</span>
                <span>MRP ₹{p.mrp}</span>
            </div>
            <div
                className="overflow-hidden"
                style={{
                    fontSize: '1.62mm',
                    lineHeight: 1.15,
                    height: '3.8mm',
                    marginTop: '0.4mm',
                }}
            >
                {p.product_name}
            </div>
            <div style={{ marginTop: '0.6mm' }}>
                <Bars bars={p.bars} height="7.9mm" />
            </div>
            <div
                className="text-center"
                style={{ fontSize: '1.9mm', letterSpacing: '0.15mm' }}
            >
                {p.upc}
            </div>
            <div
                className="flex justify-between"
                style={{ fontSize: '1.7mm', marginTop: '0.3mm' }}
            >
                <span>
                    MFG {mfg} &nbsp; EXP {exp}
                </span>
                <span>{p.uom}</span>
            </div>
        </div>
    );
}

function BoxLabel({ p, mfg, exp }: { p: Product; mfg: string; exp: string }) {
    const rows: [string, string][] = [
        ['PID', p.pid ?? '—'],
        ['UPC', p.upc],
        ['MRP', `₹ ${p.mrp} (incl. of all taxes)`],
        ['UOM', p.uom ?? '—'],
        ['Mfg. date', mfg],
        ['Expiry date', exp],
    ];

    return (
        <div
            className="flex shrink-0 flex-col overflow-hidden bg-white text-black shadow"
            style={{
                width: '4in',
                height: '6in',
                padding: '7mm 8mm',
                gap: '2.4mm',
                fontFamily: 'Arial, Helvetica, sans-serif',
            }}
        >
            <div
                className="font-bold"
                style={{
                    fontSize: '7.6mm',
                    borderBottom: '0.6mm solid #000',
                    paddingBottom: '1.5mm',
                }}
            >
                {p.brand_name}
            </div>
            <div
                className="font-bold"
                style={{
                    fontSize: '3.9mm',
                    lineHeight: 1.2,
                    minHeight: '14mm',
                }}
            >
                {p.product_name}
            </div>
            <table
                className="w-full border-collapse"
                style={{ fontSize: '3.6mm' }}
            >
                <tbody>
                    {rows.map(([k, v]) => (
                        <tr key={k}>
                            <td
                                className="font-bold"
                                style={{
                                    border: '0.3mm solid #000',
                                    padding: '1.1mm 2mm',
                                    width: '30mm',
                                }}
                            >
                                {k}
                            </td>
                            <td
                                style={{
                                    border: '0.3mm solid #000',
                                    padding: '1.1mm 2mm',
                                }}
                            >
                                {v}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            <div
                className="text-center font-bold"
                style={{
                    fontSize: '6mm',
                    border: '0.6mm solid #000',
                    padding: '1.5mm 0',
                }}
            >
                {p.units_in_box
                    ? `${p.units_in_box} UNITS IN BOX`
                    : 'UNITS IN BOX: —'}
            </div>
            <div style={{ marginTop: 'auto' }}>
                <Bars bars={p.bars} height="24mm" />
                <div
                    className="text-center"
                    style={{
                        fontSize: '4.6mm',
                        letterSpacing: '0.35mm',
                        marginTop: '1mm',
                    }}
                >
                    {p.upc}
                </div>
            </div>
        </div>
    );
}

export default function BlinkitLabels({
    products,
    most,
    can,
}: {
    products: Product[];
    most: number;
    can: { manage: boolean; print: boolean };
}) {
    const [selectedId, setSelectedId] = useState<number | null>(
        products[0]?.id ?? null,
    );
    const [adding, setAdding] = useState(products.length === 0);
    const [kind, setKind] = useState<Kind>('sku');
    const [mfg, setMfg] = useState(thisMonth());
    const [exp, setExp] = useState(thisMonth(35));
    const [copies, setCopies] = useState('10');

    const selected = products.find((p) => p.id === selectedId) ?? null;

    const form = useForm({ ...blank });

    const choose = (p: Product) => {
        setAdding(false);
        setSelectedId(p.id);
        form.clearErrors();
        form.setData({
            brand_name: p.brand_name,
            product_name: p.product_name,
            upc: p.upc,
            pid: p.pid ?? '',
            mrp: p.mrp,
            uom: p.uom ?? '',
            units_in_box: p.units_in_box ? String(p.units_in_box) : '',
            is_active: p.is_active,
        });
    };

    // Load the first product into the form once.
    useEffect(() => {
        if (selected) {
            choose(selected);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const startAdding = () => {
        setAdding(true);
        form.clearErrors();
        form.setData({ ...blank });
    };

    const save = (e: FormEvent) => {
        e.preventDefault();

        if (adding) {
            form.post(store().url, {
                preserveScroll: true,
                onSuccess: () => setAdding(false),
            });
        } else if (selected) {
            form.patch(update(selected.id).url, { preserveScroll: true });
        }
    };

    const count = Math.max(1, Math.min(most, Number(copies) || 1));
    const rows = Math.ceil(count / 2);
    const datesWrong = mfg !== '' && exp !== '' && exp <= mfg;

    const printUrl = useMemo(
        () =>
            selected
                ? printRoute(selected.id, {
                      query: { type: kind, mfg, exp, copies: String(count) },
                  }).url
                : null,
        [selected, kind, mfg, exp, count],
    );

    const preview: Product | null = adding ? null : selected;

    return (
        <>
            <Head title="Blinkit Labels" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Blinkit Labels"
                    description="Labels for Blinkit stock: a small SKU label for each bottle (50 × 25 mm, two side by side on the 100 mm roll) and a box label for each carton (4 × 6 inch). Keep each product's details once; give the dates and how many to print each time."
                />

                <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)]">
                    <section className="bg-card overflow-hidden rounded-2xl border">
                        <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                            <h2 className="text-sm font-semibold">Products</h2>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={startAdding}
                                >
                                    <Plus className="size-4" />
                                    Add product
                                </Button>
                            )}
                        </div>
                        <ul>
                            {products.map((p) => (
                                <li
                                    key={p.id}
                                    className="border-b last:border-0"
                                >
                                    <button
                                        type="button"
                                        onClick={() => choose(p)}
                                        className={cn(
                                            'grid w-full grid-cols-[1fr_auto] items-center gap-2 px-4 py-2.5 text-left',
                                            !adding && p.id === selectedId
                                                ? 'bg-accent'
                                                : 'hover:bg-muted/50',
                                        )}
                                    >
                                        <span className="min-w-0">
                                            <span
                                                className={cn(
                                                    'block font-medium',
                                                    !p.is_active &&
                                                        'text-muted-foreground line-through',
                                                )}
                                            >
                                                {p.product_name}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                PID {p.pid ?? '—'} · UPC{' '}
                                                <span className="font-mono">
                                                    {p.upc}
                                                </span>{' '}
                                                · ₹{p.mrp}
                                            </span>
                                        </span>
                                        {p.units_in_box ? (
                                            <StatusBadge variant="success">
                                                {p.units_in_box} / box
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge variant="warning">
                                                Box qty missing
                                            </StatusBadge>
                                        )}
                                    </button>
                                </li>
                            ))}
                            {products.length === 0 && (
                                <li className="text-muted-foreground px-4 py-6 text-center text-sm">
                                    No products yet. Add the first one below.
                                </li>
                            )}
                        </ul>

                        {can.manage && (adding || selected) && (
                            <form
                                onSubmit={save}
                                className="grid gap-3 border-t p-4 sm:grid-cols-2"
                            >
                                <h3 className="text-sm font-semibold sm:col-span-2">
                                    {adding ? 'New product' : 'Product details'}
                                </h3>
                                {(
                                    [
                                        ['brand_name', 'Brand name', false],
                                        ['pid', 'PID', false],
                                        ['product_name', 'Product name', true],
                                        ['upc', 'UPC (barcode)', false],
                                        ['mrp', 'MRP (₹)', false],
                                        ['uom', 'UOM', false],
                                        ['units_in_box', 'Units in box', false],
                                    ] as const
                                ).map(([key, label, wide]) => (
                                    <div
                                        key={key}
                                        className={cn(
                                            'grid gap-1',
                                            wide && 'sm:col-span-2',
                                        )}
                                    >
                                        <Label htmlFor={`bl-${key}`}>
                                            {label}
                                        </Label>
                                        <Input
                                            id={`bl-${key}`}
                                            value={String(form.data[key] ?? '')}
                                            inputMode={
                                                key === 'mrp'
                                                    ? 'decimal'
                                                    : key === 'units_in_box'
                                                      ? 'numeric'
                                                      : undefined
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    key,
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors[key]}
                                        />
                                    </div>
                                ))}
                                {!adding && (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="checkbox"
                                            checked={form.data.is_active}
                                            onChange={(e) =>
                                                form.setData(
                                                    'is_active',
                                                    e.target.checked,
                                                )
                                            }
                                        />
                                        Still sold on Blinkit
                                    </label>
                                )}
                                <div className="flex gap-2 sm:col-span-2">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        {adding
                                            ? 'Add product'
                                            : 'Save product'}
                                    </Button>
                                    {adding && products.length > 0 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                products[0] &&
                                                choose(products[0])
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    )}
                                </div>
                            </form>
                        )}
                    </section>

                    <section className="bg-card overflow-hidden rounded-2xl border">
                        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                            <h2 className="text-sm font-semibold">
                                Print labels
                            </h2>
                            <div
                                className="inline-flex overflow-hidden rounded-lg border"
                                role="group"
                                aria-label="Label type"
                            >
                                {(
                                    [
                                        ['sku', 'SKU label · 2 × 50×25 mm'],
                                        ['box', 'Box label · 4×6 in'],
                                    ] as const
                                ).map(([k, label]) => (
                                    <button
                                        key={k}
                                        type="button"
                                        onClick={() => setKind(k)}
                                        className={cn(
                                            'px-3 py-1.5 text-sm',
                                            kind === k
                                                ? 'bg-primary text-primary-foreground font-medium'
                                                : 'hover:bg-muted/50',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="grid gap-4 p-4">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="grid gap-1">
                                    <Label htmlFor="bl-mfg">
                                        Manufacturing date
                                    </Label>
                                    <Input
                                        id="bl-mfg"
                                        type="month"
                                        value={mfg}
                                        onChange={(e) => setMfg(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="bl-exp">Expiry date</Label>
                                    <Input
                                        id="bl-exp"
                                        type="month"
                                        value={exp}
                                        onChange={(e) => setExp(e.target.value)}
                                    />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="bl-copies">
                                        How many labels
                                    </Label>
                                    <Input
                                        id="bl-copies"
                                        type="number"
                                        min={1}
                                        max={most}
                                        value={copies}
                                        onChange={(e) =>
                                            setCopies(e.target.value)
                                        }
                                    />
                                </div>
                            </div>
                            {datesWrong && (
                                <p className="text-sm text-red-700 dark:text-red-300">
                                    The expiry date must be after the
                                    manufacturing date.
                                </p>
                            )}

                            <div className="bg-muted/40 flex flex-col items-center gap-3 overflow-x-auto rounded-xl p-4">
                                {preview === null ? (
                                    <p className="text-muted-foreground py-10 text-sm">
                                        {adding
                                            ? 'Save the product to see its labels.'
                                            : 'Choose a product.'}
                                    </p>
                                ) : kind === 'sku' ? (
                                    <div
                                        className="flex shadow"
                                        style={{ width: '100mm' }}
                                    >
                                        <SkuLabel
                                            p={preview}
                                            mfg={monthLabel(mfg)}
                                            exp={monthLabel(exp)}
                                        />
                                        <div className="border-l border-dashed border-neutral-300" />
                                        {count > 1 ? (
                                            <SkuLabel
                                                p={preview}
                                                mfg={monthLabel(mfg)}
                                                exp={monthLabel(exp)}
                                            />
                                        ) : (
                                            <div
                                                className="shrink-0 bg-white"
                                                style={{
                                                    width: '50mm',
                                                    height: '25mm',
                                                }}
                                            />
                                        )}
                                    </div>
                                ) : (
                                    <BoxLabel
                                        p={preview}
                                        mfg={monthLabel(mfg)}
                                        exp={monthLabel(exp)}
                                    />
                                )}
                            </div>
                            <p className="text-muted-foreground text-xs">
                                {kind === 'sku'
                                    ? `Shown at actual size: each label 50 × 25 mm, two side by side on a 100 × 25 mm row. ${count} label(s) print on ${rows} row(s)${count % 2 ? ', the last with one blank' : ''}.`
                                    : `Shown at actual size: 4 × 6 inch. ${count} label(s) print, one per label.`}
                            </p>

                            {can.print && (
                                <div>
                                    <Button
                                        asChild
                                        disabled={
                                            !printUrl ||
                                            datesWrong ||
                                            !selected?.bars
                                        }
                                    >
                                        <a
                                            href={
                                                printUrl &&
                                                !datesWrong &&
                                                selected?.bars
                                                    ? printUrl
                                                    : undefined
                                            }
                                            target="_blank"
                                            rel="noopener"
                                            aria-disabled={
                                                !printUrl ||
                                                datesWrong ||
                                                !selected?.bars
                                            }
                                        >
                                            <Printer className="size-4" />
                                            Print {count} label(s)
                                        </a>
                                    </Button>
                                    <p className="text-muted-foreground mt-2 text-xs">
                                        Opens a PDF at the exact label size.
                                        Print it at 100% (actual size), not "fit
                                        to page".
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}

BlinkitLabels.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Blinkit Labels', href: labelsIndex() },
    ],
};
