import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    FileText,
    LoaderCircle,
    Pencil,
    Plus,
    Printer,
    RefreshCw,
    Tags,
    Trash2,
    Upload,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TONE_VARIANT, rupees, when } from '@/lib/dispatch';
import {
    courierName,
    needsAttention,
    postJson,
    printPlan,
    type Abilities,
    type Batch,
    type Parcel,
    type PrintPlan,
} from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { index as listingsIndex } from '@/routes/listings';
import {
    close,
    create,
    hold,
    index,
    print as printRoute,
} from '@/routes/online-orders';
import { destroy as destroyFile } from '@/routes/online-orders/files';
import {
    cancel as cancelParcel,
    pack as packParcel,
    update as updateParcel,
} from '@/routes/online-orders/parcels';

type LabelFileRow = {
    id: number;
    name: string;
    pages: number;
    read_with: string | null;
    warnings: string[];
    shipments: number;
    uploaded_by: string | null;
    uploaded_at: string | null;
};

type Shortfall = {
    item_id: number;
    code: string;
    name: string;
    unit: string | null;
    needed: string;
    free: string;
    short: string;
};

type PrintRow = {
    scope: string;
    courier: string | null;
    shipments: number;
    pages: number;
    by: string | null;
    at: string | null;
};

type Filter =
    | 'all'
    | 'attention'
    | 'unprinted'
    | 'topack'
    | 'packed'
    | 'cancelled';

const READ_WITH: Record<string, string> = {
    meesho: 'Read from the label text',
    flipkart: 'Read from the label text',
    ai: 'Read by the AI reader',
    'meesho+ai': 'Label text, rest by the AI reader',
    'flipkart+ai': 'Label text, rest by the AI reader',
    none: 'Could not be read',
};

function ReasonDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    url,
    destructive,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    url: string;
    destructive?: boolean;
}) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                onOpenChange(false);
                            },
                        });
                    }}
                    className="space-y-3"
                >
                    <Label htmlFor="reason">Reason</Label>
                    <Input
                        id="reason"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        autoFocus
                        required
                    />
                    <InputError message={form.errors.reason} />
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Back
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={
                                form.processing ||
                                form.data.reason.trim() === ''
                            }
                        >
                            {confirmLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditParcel({
    parcel,
    onClose,
}: {
    parcel: Parcel;
    onClose: () => void;
}) {
    const form = useForm({
        awb: parcel.awb ?? '',
        courier: parcel.courier ?? '',
        order_number: parcel.order_number ?? '',
        payment_mode: parcel.payment_mode,
        lines:
            parcel.lines.length > 0
                ? parcel.lines.map((l) => ({
                      seller_sku: l.seller_sku,
                      quantity: l.quantity,
                  }))
                : [{ seller_sku: '', quantity: 1 }],
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(updateParcel(parcel.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const setLine = (
        i: number,
        key: 'seller_sku' | 'quantity',
        value: string,
    ) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) =>
                j === i
                    ? {
                          ...l,
                          [key]:
                              key === 'quantity'
                                  ? Math.max(1, Number(value) || 1)
                                  : value,
                      }
                    : l,
            ),
        );

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Correct the parcel</DialogTitle>
                    <DialogDescription>
                        Type what the label says where the reader could not. The
                        product is matched from the SKU text.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label htmlFor="awb">AWB (under the barcode)</Label>
                            <Input
                                id="awb"
                                value={form.data.awb}
                                onChange={(e) =>
                                    form.setData('awb', e.target.value)
                                }
                                className="font-mono"
                            />
                            <InputError message={form.errors.awb} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="courier">Courier</Label>
                            <Input
                                id="courier"
                                value={form.data.courier}
                                onChange={(e) =>
                                    form.setData('courier', e.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="order_number">Order number</Label>
                            <Input
                                id="order_number"
                                value={form.data.order_number}
                                onChange={(e) =>
                                    form.setData('order_number', e.target.value)
                                }
                                className="font-mono"
                            />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="payment_mode">Payment</Label>
                            <select
                                id="payment_mode"
                                className="border-input bg-background h-9 w-full rounded-md border px-2 text-sm"
                                value={form.data.payment_mode}
                                onChange={(e) =>
                                    form.setData(
                                        'payment_mode',
                                        e.target
                                            .value as Parcel['payment_mode'],
                                    )
                                }
                            >
                                <option value="cod">COD</option>
                                <option value="prepaid">Prepaid</option>
                                <option value="unknown">Not known</option>
                            </select>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <Label>
                            What goes in it (SKU as the marketplace prints it)
                        </Label>
                        {form.data.lines.map((l, i) => (
                            <div key={i} className="flex gap-2">
                                <Input
                                    value={l.seller_sku}
                                    onChange={(e) =>
                                        setLine(i, 'seller_sku', e.target.value)
                                    }
                                    placeholder="SKU"
                                    aria-label={`SKU ${i + 1}`}
                                />
                                <Input
                                    type="number"
                                    min={1}
                                    value={l.quantity}
                                    onChange={(e) =>
                                        setLine(i, 'quantity', e.target.value)
                                    }
                                    className="w-20"
                                    aria-label={`Quantity ${i + 1}`}
                                />
                                {form.data.lines.length > 1 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remove line"
                                        onClick={() =>
                                            form.setData(
                                                'lines',
                                                form.data.lines.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('lines', [
                                    ...form.data.lines,
                                    { seller_sku: '', quantity: 1 },
                                ])
                            }
                        >
                            <Plus className="size-4" /> Add a product
                        </Button>
                        <InputError
                            message={
                                Object.entries(form.errors).find(([k]) =>
                                    k.startsWith('lines'),
                                )?.[1]
                            }
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Back
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function OnlineOrderBatch({
    batch,
    files,
    shipments,
    unmapped,
    shortfall,
    prints,
    cutoff,
    past_cutoff,
    can,
}: {
    batch: Batch;
    files: LabelFileRow[];
    shipments: Parcel[];
    unmapped: {
        seller_sku: string;
        parcels: number;
        description: string | null;
    }[];
    shortfall: Shortfall[];
    prints: PrintRow[];
    cutoff: string;
    past_cutoff: boolean;
    can: Abilities & { upload_here: boolean; close: boolean; correct: boolean };
}) {
    const [filter, setFilter] = useState<Filter>('all');
    const [printing, setPrinting] = useState(false);
    const [editing, setEditing] = useState<Parcel | null>(null);
    const [cancelling, setCancelling] = useState<Parcel | null>(null);
    const [forcing, setForcing] = useState<Parcel | null>(null);

    const live = shipments.filter((s) => s.status !== 'cancelled');
    const counts = {
        all: shipments.length,
        attention: shipments.filter(needsAttention).length,
        unprinted: shipments.filter((s) => s.status === 'uploaded').length,
        topack: shipments.filter(
            (s) => s.status === 'uploaded' || s.status === 'printed',
        ).length,
        packed: shipments.filter(
            (s) => s.status === 'packed' || s.status === 'handed_over',
        ).length,
        cancelled: shipments.filter((s) => s.status === 'cancelled').length,
    };

    const shown = shipments.filter((s) => {
        switch (filter) {
            case 'attention':
                return needsAttention(s);
            case 'unprinted':
                return s.status === 'uploaded';
            case 'topack':
                return s.status === 'uploaded' || s.status === 'printed';
            case 'packed':
                return s.status === 'packed' || s.status === 'handed_over';
            case 'cancelled':
                return s.status === 'cancelled';
            default:
                return true;
        }
    });

    const groups = useMemo(() => {
        const map = new Map<string, Parcel[]>();
        shown.forEach((s) => {
            const key = courierName(s.courier);
            map.set(key, [...(map.get(key) ?? []), s]);
        });

        return Array.from(map.entries());
    }, [shown]);

    const couriers = useMemo(
        () =>
            Array.from(
                new Map(
                    live.map((s) => [s.courier ?? '', courierName(s.courier)]),
                ).entries(),
            ),
        [live],
    );

    const doPrint = async (
        scope: 'all' | 'unprinted' | 'courier' | 'one',
        extra: { courier?: string | null; shipment_id?: number } = {},
    ) => {
        setPrinting(true);

        try {
            const { ok, data } = await postJson<
                PrintPlan & { message?: string }
            >(printRoute(batch.id).url, { scope, ...extra });

            if (!ok) {
                toast.error(data.message ?? 'The labels could not be printed.');

                return;
            }

            await printPlan(data);
            toast.success(
                `${data.shipments} label(s), ${data.pages} page(s) sent to print.`,
            );
            router.reload({ only: ['shipments', 'prints', 'shortfall'] });
        } catch (e) {
            toast.error(e instanceof Error ? e.message : String(e));
        } finally {
            setPrinting(false);
        }
    };

    const warnings = files.flatMap((f) =>
        f.warnings.map((w) => ({ file: f.name, warning: w })),
    );

    return (
        <>
            <Head title={`${batch.number} · Online orders`} />
            <div className="space-y-6">
                <PageHeader
                    title={`${batch.brand} on ${batch.marketplace}`}
                    description={`${batch.number} · ships from ${batch.facility} · ${batch.store} · ${new Date(`${batch.for_date}T00:00:00`).toLocaleDateString('en-IN', { dateStyle: 'medium' })}`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link
                                    href={index({
                                        query: { date: batch.for_date },
                                    })}
                                >
                                    All uploads
                                </Link>
                            </Button>
                            {can.upload_here && (
                                <Button variant="outline" asChild>
                                    <Link href={create()}>
                                        <Upload className="size-4" />
                                        Upload more
                                    </Link>
                                </Button>
                            )}
                            {(can.print || can.manage || can.upload) && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            hold(batch.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    <RefreshCw className="size-4" />
                                    Check stock again
                                </Button>
                            )}
                            {can.print && live.length > 0 && (
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button disabled={printing}>
                                            {printing ? (
                                                <LoaderCircle className="size-4 animate-spin" />
                                            ) : (
                                                <Printer className="size-4" />
                                            )}
                                            Print labels
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        align="end"
                                        className="w-64"
                                    >
                                        <DropdownMenuItem
                                            disabled={counts.unprinted === 0}
                                            onSelect={() =>
                                                doPrint('unprinted')
                                            }
                                        >
                                            Not printed yet ({counts.unprinted})
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            onSelect={() => doPrint('all')}
                                        >
                                            All, again ({live.length})
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuLabel>
                                            One courier&rsquo;s pile
                                        </DropdownMenuLabel>
                                        {couriers.map(([value, label]) => (
                                            <DropdownMenuItem
                                                key={value}
                                                onSelect={() =>
                                                    doPrint('courier', {
                                                        courier: value || null,
                                                    })
                                                }
                                            >
                                                {label} (
                                                {
                                                    live.filter(
                                                        (s) =>
                                                            (s.courier ??
                                                                '') === value,
                                                    ).length
                                                }
                                                )
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                            {can.close && (
                                <ConfirmDialog
                                    trigger={
                                        <Button>
                                            <CheckCircle2 className="size-4" />
                                            That&rsquo;s all for today
                                        </Button>
                                    }
                                    title="Is every label for today uploaded?"
                                    description={`The depot is told ${live.length} parcel(s) are ready to print. Labels released later go into a new batch.`}
                                    confirmLabel="Yes, tell the depot"
                                    action={() =>
                                        router.post(
                                            close(batch.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <StatusBadge
                        variant={batch.status === 'open' ? 'info' : 'muted'}
                    >
                        {batch.status_label}
                    </StatusBadge>
                    <span className="text-muted-foreground">
                        Uploaded by {batch.uploaded_by ?? '—'}
                        {batch.closed_at
                            ? ` · closed ${when(batch.closed_at)} by ${batch.closed_by ?? '—'}`
                            : ''}
                    </span>
                </div>

                {past_cutoff && counts.topack > 0 && (
                    <div
                        className="flex items-start gap-3 rounded-xl border border-red-600/30 bg-red-500/10 p-4 text-sm"
                        role="alert"
                    >
                        <AlertTriangle className="size-5 shrink-0 text-red-600" />
                        <span>
                            <b>{counts.topack} parcel(s) are not packed</b> and
                            it is past {cutoff}. Pack each one by scanning its
                            label, or cancel it with a reason.
                        </span>
                    </div>
                )}

                {unmapped.length > 0 && (
                    <section className="rounded-xl border border-amber-600/30 bg-amber-500/5 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <Tags className="size-4" />
                                    {unmapped.length} SKU(s) not matched to a
                                    product
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Those parcels cannot be packed until the ERP
                                    knows which product each SKU is. Once
                                    mapped, it is remembered for every label
                                    after.
                                </p>
                            </div>
                            {can.manage && (
                                <Button asChild size="sm">
                                    <Link href={listingsIndex()}>Map SKUs</Link>
                                </Button>
                            )}
                        </div>
                        <ul className="mt-3 space-y-1 text-sm">
                            {unmapped.map((u) => (
                                <li key={u.seller_sku}>
                                    <span className="font-mono">
                                        {u.seller_sku}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {u.parcels} parcel(s)
                                        {u.description
                                            ? ` · ${u.description}`
                                            : ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {shortfall.length > 0 && (
                    <section className="rounded-xl border border-red-600/30 bg-red-500/5 p-4">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <AlertTriangle className="size-4" />
                            Not enough stock in {batch.store}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Bring stock in (a transfer from the factory), then
                            press <b>Check stock again</b>.
                        </p>
                        <table className="mt-3 w-full text-sm">
                            <thead className="text-muted-foreground text-left text-xs uppercase">
                                <tr>
                                    <th className="py-1 font-medium">
                                        Product
                                    </th>
                                    <th className="py-1 text-right font-medium">
                                        Needed
                                    </th>
                                    <th className="py-1 text-right font-medium">
                                        Free
                                    </th>
                                    <th className="py-1 text-right font-medium">
                                        Short
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {shortfall.map((r) => (
                                    <tr key={r.item_id} className="border-t">
                                        <td className="py-2">
                                            {r.name}{' '}
                                            <span className="text-muted-foreground font-mono text-xs">
                                                {r.code}
                                            </span>
                                        </td>
                                        <td className="py-2 text-right tabular-nums">
                                            {r.needed} {r.unit}
                                        </td>
                                        <td className="py-2 text-right tabular-nums">
                                            {r.free}
                                        </td>
                                        <td className="py-2 text-right font-semibold text-red-700 tabular-nums dark:text-red-300">
                                            {r.short}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </section>
                )}

                {warnings.length > 0 && (
                    <section className="rounded-xl border border-amber-600/30 bg-amber-500/5 p-4 text-sm">
                        <h2 className="mb-2 font-semibold">
                            From the label reader
                        </h2>
                        <ul className="list-disc space-y-1 pl-5">
                            {warnings.map((w, i) => (
                                <li key={i}>
                                    {w.warning}{' '}
                                    <span className="text-muted-foreground">
                                        ({w.file})
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                        <h2 className="mr-2 text-sm font-semibold">Parcels</h2>
                        {(
                            [
                                ['all', 'All'],
                                ['attention', 'Need attention'],
                                ['unprinted', 'Not printed'],
                                ['topack', 'To pack'],
                                ['packed', 'Packed'],
                                ['cancelled', 'Cancelled'],
                            ] as [Filter, string][]
                        ).map(([key, label]) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setFilter(key)}
                                className={cn(
                                    'rounded-full border px-3 py-1 text-xs',
                                    filter === key
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'hover:bg-muted',
                                    key === 'attention' &&
                                        counts.attention > 0 &&
                                        filter !== key &&
                                        'border-red-600/40 text-red-700 dark:text-red-300',
                                )}
                            >
                                {label} ({counts[key]})
                            </button>
                        ))}
                    </div>

                    {groups.length === 0 ? (
                        <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                            Nothing here.
                        </p>
                    ) : (
                        groups.map(([courier, parcels]) => (
                            <div key={courier}>
                                <div className="bg-muted/40 flex items-center justify-between border-b px-4 py-2 text-xs font-semibold tracking-wide uppercase">
                                    <span>{courier}</span>
                                    <span className="text-muted-foreground">
                                        {parcels.length}
                                    </span>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <tbody className="divide-y">
                                            {parcels.map((p) => (
                                                <tr
                                                    key={p.id}
                                                    className={cn(
                                                        'align-top',
                                                        needsAttention(p) &&
                                                            'bg-red-500/5',
                                                        p.status ===
                                                            'cancelled' &&
                                                            'opacity-60',
                                                    )}
                                                >
                                                    <td className="px-4 py-3">
                                                        <div className="font-mono font-medium">
                                                            {p.awb ?? (
                                                                <span className="text-red-700 dark:text-red-300">
                                                                    No AWB
                                                                </span>
                                                            )}
                                                        </div>
                                                        <div className="text-muted-foreground font-mono text-xs">
                                                            {p.order_number ??
                                                                '—'}
                                                        </div>
                                                        <div className="text-muted-foreground text-xs">
                                                            page{' '}
                                                            {p.pages.join(', ')}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {p.lines.length ===
                                                        0 ? (
                                                            <span className="text-red-700 dark:text-red-300">
                                                                Product not on
                                                                label
                                                            </span>
                                                        ) : (
                                                            p.lines.map((l) => (
                                                                <div key={l.id}>
                                                                    <span className="font-medium">
                                                                        {l.item ??
                                                                            l.seller_sku}
                                                                    </span>{' '}
                                                                    ×{' '}
                                                                    {l.quantity}
                                                                    {l.item && (
                                                                        <div className="text-muted-foreground text-xs">
                                                                            {
                                                                                l.seller_sku
                                                                            }
                                                                            {l.units &&
                                                                            Number(
                                                                                l.units,
                                                                            ) !==
                                                                                l.quantity
                                                                                ? ` · ${l.units} units`
                                                                                : ''}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            ))
                                                        )}
                                                        {p.warnings.length >
                                                            0 &&
                                                            p.status !==
                                                                'packed' &&
                                                            p.status !==
                                                                'handed_over' && (
                                                                <div className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                                                    {p.warnings.join(
                                                                        ' ',
                                                                    )}
                                                                </div>
                                                            )}
                                                    </td>
                                                    <td className="px-4 py-3 whitespace-nowrap">
                                                        <div>
                                                            {p.payment_label}
                                                        </div>
                                                        <div className="text-muted-foreground text-xs">
                                                            {p.payable_amount
                                                                ? rupees(
                                                                      p.payable_amount,
                                                                  )
                                                                : ''}
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="flex flex-col items-start gap-1">
                                                            <StatusBadge
                                                                variant={
                                                                    TONE_VARIANT[
                                                                        p
                                                                            .status_tone
                                                                    ]
                                                                }
                                                            >
                                                                {p.status_label}
                                                            </StatusBadge>
                                                            {p.stock_label &&
                                                                p.stock_tone &&
                                                                p.status !==
                                                                    'cancelled' && (
                                                                    <StatusBadge
                                                                        variant={
                                                                            TONE_VARIANT[
                                                                                p
                                                                                    .stock_tone
                                                                            ]
                                                                        }
                                                                    >
                                                                        {
                                                                            p.stock_label
                                                                        }
                                                                    </StatusBadge>
                                                                )}
                                                        </div>
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-3 text-xs">
                                                        {p.packed_at ? (
                                                            <>
                                                                Packed{' '}
                                                                {when(
                                                                    p.packed_at,
                                                                )}
                                                                <br />
                                                                by{' '}
                                                                {p.packed_by ??
                                                                    '—'}
                                                                {p.pack_method ===
                                                                    'manual' && (
                                                                    <span className="block text-amber-700 dark:text-amber-300">
                                                                        without
                                                                        a scan:{' '}
                                                                        {
                                                                            p.pack_note
                                                                        }
                                                                    </span>
                                                                )}
                                                            </>
                                                        ) : p.cancel_reason ? (
                                                            <>
                                                                Cancelled:{' '}
                                                                {
                                                                    p.cancel_reason
                                                                }
                                                            </>
                                                        ) : p.print_count >
                                                          0 ? (
                                                            <>
                                                                Printed{' '}
                                                                {when(
                                                                    p.printed_at,
                                                                )}
                                                                {p.print_count >
                                                                1
                                                                    ? ` · ${p.print_count} times`
                                                                    : ''}
                                                            </>
                                                        ) : (
                                                            'Not printed'
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right whitespace-nowrap">
                                                        <div className="flex justify-end gap-1">
                                                            {can.print &&
                                                                p.status !==
                                                                    'cancelled' && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        title="Print this label"
                                                                        aria-label="Print this label"
                                                                        disabled={
                                                                            printing
                                                                        }
                                                                        onClick={() =>
                                                                            doPrint(
                                                                                'one',
                                                                                {
                                                                                    shipment_id:
                                                                                        p.id,
                                                                                },
                                                                            )
                                                                        }
                                                                    >
                                                                        <Printer className="size-4" />
                                                                    </Button>
                                                                )}
                                                            {can.correct &&
                                                                (p.status ===
                                                                    'uploaded' ||
                                                                    p.status ===
                                                                        'printed') && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        title="Correct"
                                                                        aria-label="Correct"
                                                                        onClick={() =>
                                                                            setEditing(
                                                                                p,
                                                                            )
                                                                        }
                                                                    >
                                                                        <Pencil className="size-4" />
                                                                    </Button>
                                                                )}
                                                            {can.manage &&
                                                                (p.status ===
                                                                    'uploaded' ||
                                                                    p.status ===
                                                                        'printed') && (
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        title="Mark packed without a scan"
                                                                        aria-label="Mark packed without a scan"
                                                                        onClick={() =>
                                                                            setForcing(
                                                                                p,
                                                                            )
                                                                        }
                                                                    >
                                                                        <CheckCircle2 className="size-4" />
                                                                    </Button>
                                                                )}
                                                            {((can.upload &&
                                                                (p.status ===
                                                                    'uploaded' ||
                                                                    p.status ===
                                                                        'printed')) ||
                                                                (can.manage &&
                                                                    p.status !==
                                                                        'cancelled' &&
                                                                    p.status !==
                                                                        'handed_over')) && (
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    title="Cancel"
                                                                    aria-label="Cancel"
                                                                    onClick={() =>
                                                                        setCancelling(
                                                                            p,
                                                                        )
                                                                    }
                                                                >
                                                                    <Ban className="size-4" />
                                                                </Button>
                                                            )}
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        ))
                    )}
                </section>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Files
                        </h2>
                        <ul className="divide-y text-sm">
                            {files.map((f) => (
                                <li
                                    key={f.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                >
                                    <FileText className="text-muted-foreground size-4 shrink-0" />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate font-medium">
                                            {f.name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {f.pages} page(s) · {f.shipments}{' '}
                                            parcel(s) ·{' '}
                                            {READ_WITH[f.read_with ?? ''] ??
                                                f.read_with}{' '}
                                            · {f.uploaded_by ?? '—'},{' '}
                                            {when(f.uploaded_at)}
                                        </div>
                                    </div>
                                    {(can.upload || can.manage) && (
                                        <ConfirmDialog
                                            trigger={
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Remove ${f.name}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            }
                                            title={`Remove ${f.name}?`}
                                            description="Its parcels are removed with it and what was held for them is let go. Not possible once any of its labels has been printed."
                                            confirmLabel="Remove"
                                            destructive
                                            action={() =>
                                                router.delete(
                                                    destroyFile(f.id).url,
                                                    {
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>

                    {prints.length > 0 && (
                        <section className="bg-card rounded-xl border">
                            <h2 className="border-b px-4 py-3 text-sm font-semibold">
                                Printed
                            </h2>
                            <ul className="divide-y text-sm">
                                {prints.map((p, i) => (
                                    <li
                                        key={i}
                                        className="flex items-center gap-3 px-4 py-3"
                                    >
                                        <Printer className="text-muted-foreground size-4" />
                                        <span className="flex-1">
                                            {p.shipments} label(s), {p.pages}{' '}
                                            page(s)
                                            {p.scope === 'courier'
                                                ? ` · ${courierName(p.courier)}`
                                                : p.scope === 'unprinted'
                                                  ? ' · not yet printed'
                                                  : p.scope === 'one'
                                                    ? ' · one label'
                                                    : ' · all'}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {p.by ?? '—'}, {when(p.at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>
            </div>

            {editing && (
                <EditParcel parcel={editing} onClose={() => setEditing(null)} />
            )}
            {cancelling && (
                <ReasonDialog
                    open
                    onOpenChange={(o) => !o && setCancelling(null)}
                    title={`Cancel ${cancelling.awb ?? cancelling.order_number ?? 'this parcel'}?`}
                    description={
                        cancelling.status === 'packed'
                            ? 'It has been packed: its stock goes back on the shelf. Unpack the box.'
                            : 'What was held for it is let go. A cancelled parcel is refused at the packing table.'
                    }
                    confirmLabel="Cancel parcel"
                    url={cancelParcel(cancelling.id).url}
                    destructive
                />
            )}
            {forcing && (
                <ReasonDialog
                    open
                    onOpenChange={(o) => !o && setForcing(null)}
                    title="Mark packed without scanning?"
                    description="The stock leaves now. Recorded with your name and the reason, so use it only when the label cannot be scanned."
                    confirmLabel="Mark packed"
                    url={packParcel(forcing.id).url}
                />
            )}
        </>
    );
}
