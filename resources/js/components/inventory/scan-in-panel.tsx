import { router } from '@inertiajs/react';
import { Camera, Minus, Plus, ScanLine, Trash2 } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import { BarcodeCamera } from '@/components/barcode-camera';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SearchableSelect,
    type SearchableOption,
} from '@/components/ui/searchable-select';
import { cn } from '@/lib/utils';
import { book, lookup } from '@/routes/stock/scan-in';

export type ScanProduct = SearchableOption & {
    shelf_life_days: number | null;
};

type Line = {
    key: string;
    product_id: string;
    name: string;
    code: string;
    pack: number;
    quantity: number;
    came_in: string;
    manufactured_at: string;
    expiry_at: string;
    barcode: string | null;
};

type Notice = { tone: 'good' | 'warn' | 'bad'; text: string } | null;

const today = () => new Date().toLocaleDateString('en-CA');

function addDays(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return d.toLocaleDateString('en-CA');
}

const packLabel = (pack: number) => (pack > 1 ? `Pack of ${pack}` : 'Single');

/**
 * Scan finished goods onto a store's shelf: each scan adds a piece (or a
 * pack) of the product with the dates set at the top. A barcode the ERP
 * does not know yet is matched once to a product and kept.
 */
export function ScanInPanel({
    storeId,
    storeName,
    products,
}: {
    storeId: number;
    storeName: string;
    products: ScanProduct[];
}) {
    const [open, setOpen] = useState(false);
    const [cameIn, setCameIn] = useState(today);
    const [made, setMade] = useState('');
    const [expiry, setExpiry] = useState('');
    const [productId, setProductId] = useState('');
    const [pack, setPack] = useState(1);
    const [quantity, setQuantity] = useState('1');
    const [code, setCode] = useState('');
    const [camera, setCamera] = useState(false);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<Notice>(null);
    const [pending, setPending] = useState<string | null>(null);
    const [lines, setLines] = useState<Line[]>([]);
    const known = useRef(
        new Map<string, { product_id: string; pack: number | null }>(),
    );
    const codeInput = useRef<HTMLInputElement>(null);

    const focusScan = () =>
        requestAnimationFrame(() => codeInput.current?.focus());

    const expiryFor = (product: ScanProduct | undefined) => {
        if (expiry !== '') {
            return expiry;
        }

        return made !== '' && product?.shelf_life_days
            ? addDays(made, product.shelf_life_days)
            : '';
    };

    const addLine = (
        id: string,
        packOf: number,
        count: number,
        barcode: string | null,
    ) => {
        const product = products.find((p) => p.value === id);

        if (!product) {
            setNotice({ tone: 'bad', text: 'Choose the product first.' });

            return;
        }

        const lineExpiry = expiryFor(product);
        const key = [id, packOf, cameIn, made, lineExpiry].join('|');

        setLines((current) => {
            const same = current.find((l) => l.key === key);

            if (same) {
                return current.map((l) =>
                    l.key === key
                        ? {
                              ...l,
                              quantity: l.quantity + count,
                              barcode: l.barcode ?? barcode,
                          }
                        : l,
                );
            }

            return [
                {
                    key,
                    product_id: id,
                    name: product.label,
                    code: product.hint ?? '',
                    pack: packOf,
                    quantity: count,
                    came_in: cameIn,
                    manufactured_at: made,
                    expiry_at: lineExpiry,
                    barcode,
                },
                ...current,
            ];
        });
        setNotice({
            tone: 'good',
            text: `+${count} ${product.label}${packOf > 1 ? ` (${packLabel(packOf)})` : ''}`,
        });
    };

    const scan = async (value: string) => {
        const scanned = value.trim();
        setCode('');

        if (scanned === '') {
            return;
        }

        const remembered = known.current.get(scanned);

        if (remembered) {
            const packOf = remembered.pack ?? pack;
            setProductId(remembered.product_id);
            addLine(remembered.product_id, packOf, 1, null);
            focusScan();

            return;
        }

        setBusy(true);

        try {
            const response = await fetch(
                lookup({ query: { code: scanned } }).url,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );
            const data = (await response.json()) as {
                product_id?: number;
                pack?: number | null;
                message?: string;
            };

            if (!response.ok || !data.product_id) {
                setPending(scanned);
                setNotice({
                    tone: 'warn',
                    text:
                        data.message ??
                        `Barcode ${scanned} is not on any product yet.`,
                });

                return;
            }

            const id = String(data.product_id);
            known.current.set(scanned, {
                product_id: id,
                pack: data.pack ?? null,
            });
            const packOf = data.pack ?? pack;
            setProductId(id);

            if (data.pack) {
                setPack(data.pack);
            }

            addLine(id, packOf, 1, null);
        } catch {
            setNotice({
                tone: 'bad',
                text: 'Could not reach the server. Scan again.',
            });
        } finally {
            setBusy(false);
            focusScan();
        }
    };

    const add = (e?: FormEvent) => {
        e?.preventDefault();
        const count = Math.max(1, Math.floor(Number(quantity) || 1));
        addLine(productId, pack, count, pending);

        if (pending && productId) {
            known.current.set(pending, { product_id: productId, pack: null });
            setPending(null);
        }

        setQuantity('1');
        focusScan();
    };

    const change = (key: string, by: number) =>
        setLines((current) =>
            current
                .map((l) =>
                    l.key === key ? { ...l, quantity: l.quantity + by } : l,
                )
                .filter((l) => l.quantity > 0),
        );

    const pieces = lines.reduce((n, l) => n + l.quantity, 0);

    const save = () => {
        setBusy(true);
        router.post(
            book(storeId).url,
            {
                lines: lines.map((l) => ({
                    product_id: Number(l.product_id),
                    pack: l.pack,
                    quantity: l.quantity,
                    came_in: l.came_in,
                    manufactured_at: l.manufactured_at || null,
                    expiry_at: l.expiry_at || null,
                    barcode: l.barcode,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = (
                        page.props as { flash?: { toast?: { type?: string } } }
                    ).flash?.toast;

                    if (flash?.type !== 'error') {
                        setLines([]);
                        setOpen(false);
                    }
                },
                onFinish: () => setBusy(false),
            },
        );
    };

    const close = (next: boolean) => {
        if (
            !next &&
            lines.length > 0 &&
            !window.confirm(
                `${pieces} scanned piece(s) are not booked yet. Close and lose them?`,
            )
        ) {
            return;
        }

        setOpen(next);
        setCamera(false);

        if (!next) {
            setLines([]);
            setNotice(null);
            setPending(null);
        }
    };

    return (
        <>
            <Button
                variant="outline"
                onClick={() => {
                    setCameIn(today());
                    setOpen(true);
                    focusScan();
                }}
            >
                <ScanLine className="size-4" />
                Scan in stock
            </Button>

            <Dialog open={open} onOpenChange={close}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>Scan in stock · {storeName}</DialogTitle>
                        <DialogDescription>
                            Set the dates, then scan each piece. Nothing is
                            saved until you book it in.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="came_in">Came in on</Label>
                            <Input
                                id="came_in"
                                type="date"
                                value={cameIn}
                                max={today()}
                                onChange={(e) => setCameIn(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="made">Manufactured</Label>
                            <Input
                                id="made"
                                type="date"
                                value={made}
                                max={cameIn}
                                onChange={(e) => setMade(e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="expiry">Expiry</Label>
                            <Input
                                id="expiry"
                                type="date"
                                value={expiry}
                                min={made || undefined}
                                onChange={(e) => setExpiry(e.target.value)}
                            />
                            <p className="text-muted-foreground text-[11px]">
                                Blank: worked out from the product&rsquo;s shelf
                                life.
                            </p>
                        </div>
                    </div>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            void scan(code);
                        }}
                        className="space-y-2"
                    >
                        <Label htmlFor="scan_code">Scan</Label>
                        <div className="flex gap-2">
                            <div className="relative min-w-0 flex-1">
                                <ScanLine className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2" />
                                <Input
                                    id="scan_code"
                                    ref={codeInput}
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    placeholder="Scan a barcode, or type it and press Enter"
                                    autoComplete="off"
                                    className="h-11 pl-10"
                                />
                            </div>
                            <Button
                                type="button"
                                variant={camera ? 'secondary' : 'outline'}
                                className="h-11"
                                onClick={() => setCamera((on) => !on)}
                                aria-pressed={camera}
                            >
                                <Camera className="size-4" />
                                <span className="hidden sm:inline">Camera</span>
                            </Button>
                        </div>
                        {camera && (
                            <BarcodeCamera
                                onCode={(c) => void scan(c)}
                                busy={busy}
                                onClose={() => setCamera(false)}
                                className="mx-auto max-w-sm"
                            />
                        )}
                    </form>

                    <form
                        onSubmit={add}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <div className="min-w-56 flex-1 space-y-1.5">
                            <Label>Product</Label>
                            <SearchableSelect
                                value={productId}
                                onValueChange={setProductId}
                                options={products}
                                placeholder="Choose the product"
                                searchPlaceholder="Search by name or code…"
                                searchThreshold={0}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>Pack</Label>
                            <div
                                role="radiogroup"
                                aria-label="Pack"
                                className="flex overflow-hidden rounded-md border"
                            >
                                {[1, 2].map((n) => (
                                    <button
                                        key={n}
                                        type="button"
                                        role="radio"
                                        aria-checked={pack === n}
                                        onClick={() => setPack(n)}
                                        className={cn(
                                            'h-9 px-3 text-sm',
                                            pack === n
                                                ? 'bg-primary text-primary-foreground'
                                                : 'hover:bg-muted',
                                        )}
                                    >
                                        {packLabel(n)}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="w-20 space-y-1.5">
                            <Label htmlFor="scan_qty">Count</Label>
                            <Input
                                id="scan_qty"
                                inputMode="numeric"
                                value={quantity}
                                onChange={(e) => setQuantity(e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={productId === ''}>
                            <Plus className="size-4" />
                            Add
                        </Button>
                    </form>

                    {notice && (
                        <p
                            className={cn(
                                'rounded-lg p-2.5 text-sm',
                                notice.tone === 'good' &&
                                    'bg-emerald-500/10 text-emerald-800 dark:text-emerald-200',
                                notice.tone === 'warn' &&
                                    'bg-amber-500/15 text-amber-900 dark:text-amber-100',
                                notice.tone === 'bad' &&
                                    'bg-red-500/10 text-red-800 dark:text-red-200',
                            )}
                        >
                            {notice.text}
                        </p>
                    )}

                    {lines.length > 0 && (
                        <div className="divide-y rounded-lg border text-sm">
                            {lines.map((l) => (
                                <div
                                    key={l.key}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="font-medium">
                                            {l.name}
                                            {l.pack > 1 && (
                                                <span className="bg-primary/10 text-primary ml-2 rounded px-1.5 py-0.5 text-xs">
                                                    {packLabel(l.pack)}
                                                </span>
                                            )}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {l.code} · came in {l.came_in}
                                            {l.manufactured_at &&
                                                ` · made ${l.manufactured_at}`}
                                            {l.expiry_at &&
                                                ` · expires ${l.expiry_at}`}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`One less ${l.name}`}
                                            onClick={() => change(l.key, -1)}
                                        >
                                            <Minus className="size-4" />
                                        </Button>
                                        <span className="w-10 text-center font-semibold tabular-nums">
                                            {l.quantity}
                                        </span>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`One more ${l.name}`}
                                            onClick={() => change(l.key, 1)}
                                        >
                                            <Plus className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label={`Remove ${l.name}`}
                                            onClick={() =>
                                                change(l.key, -l.quantity)
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    <DialogFooter className="items-center gap-2 sm:justify-between">
                        <span className="text-muted-foreground text-sm">
                            {pieces} piece(s) in {lines.length} line(s)
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                onClick={() => close(false)}
                                disabled={busy}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={save}
                                disabled={busy || lines.length === 0}
                            >
                                {busy ? 'Booking…' : 'Book stock in'}
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
