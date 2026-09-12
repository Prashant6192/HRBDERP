import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Field, FormSection } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import {
    index,
    lots as lotsRoute,
    store as storeRoute,
} from '@/routes/transfers';
import type { SelectOption } from '@/types';

type StoreOption = {
    id: number;
    code: string;
    name: string;
    badge: string;
    type: string;
    facility_id: number;
    facility: string;
    can_be_source: boolean;
    can_receive: boolean;
};
type ItemOption = SelectOption & { type: string; uom: string | null };
type LotOption = {
    lot_id: number | null;
    batch: string | null;
    expiry_at: string | null;
    available: string;
};
type Line = { item_id: string; quantity: string; lot_id: string };

export default function CreateTransfer({
    stores,
    items,
    preset,
    today,
}: {
    stores: StoreOption[];
    items: ItemOption[];
    preset: {
        source_warehouse_id: number | null;
        destination_warehouse_id: number | null;
        destination_facility_id: number | null;
        source_facility_id: number | null;
        item_id: number | null;
        quantity: string | null;
        reason: string | null;
    };
    today: string;
}) {
    const form = useForm<{
        source_warehouse_id: string;
        destination_warehouse_id: string;
        requires_inspection: boolean;
        expected_at: string;
        reason: string;
        notes: string;
        submit: 'draft' | 'request';
        lines: Line[];
    }>({
        source_warehouse_id: preset.source_warehouse_id
            ? String(preset.source_warehouse_id)
            : '',
        destination_warehouse_id: preset.destination_warehouse_id
            ? String(preset.destination_warehouse_id)
            : '',
        requires_inspection: false,
        expected_at: '',
        reason: preset.reason ?? '',
        notes: '',
        submit: 'request',
        lines: [
            {
                item_id: preset.item_id ? String(preset.item_id) : '',
                quantity: preset.quantity ?? '',
                lot_id: '',
            },
        ],
    });

    const source = stores.find(
        (s) => String(s.id) === form.data.source_warehouse_id,
    );
    const sources = stores.filter(
        (s) =>
            s.can_be_source &&
            (preset.source_facility_id
                ? s.facility_id === preset.source_facility_id
                : true),
    );
    const destinations = stores.filter(
        (s) =>
            s.can_receive &&
            (!source || s.facility_id !== source.facility_id) &&
            (preset.destination_facility_id
                ? s.facility_id === preset.destination_facility_id
                : true),
    );

    // Offer the items a store of this kind holds; anything goes for general stores.
    const offered = useMemo(
        () =>
            items.filter((i) => {
                if (!source) return true;
                if (source.type === 'raw_material')
                    return i.type === 'raw_material';
                if (source.type === 'packaging')
                    return i.type === 'packaging_material';
                if (source.type === 'finished_goods')
                    return i.type === 'finished_good';
                return true;
            }),
        [items, source],
    );

    const [lots, setLots] = useState<Record<string, LotOption[]>>({});

    useEffect(() => {
        if (!source) return;
        const wanted = form.data.lines
            .map((l) => l.item_id)
            .filter((id) => id && !lots[`${source.id}:${id}`]);
        wanted.forEach((itemId) => {
            fetch(
                lotsRoute({ query: { item: itemId, store: source.id } }).url,
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => r.json())
                .then((data: LotOption[]) =>
                    setLots((prev) => ({
                        ...prev,
                        [`${source.id}:${itemId}`]: data,
                    })),
                )
                .catch(() => undefined);
        });
    }, [form.data.lines, source, lots]);

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );
    const err = (i: number, f: string) =>
        (form.errors as Record<string, string>)[`lines.${i}.${f}`];

    return (
        <>
            <Head title="New stock transfer" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New stock transfer"
                    description="From a store at one facility to a store at another. Approval holds the stock at the source; dispatch moves it in transit; receipt lands it."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeRoute().url, { preserveScroll: true });
                    }}
                    className="space-y-6"
                >
                    <FormSection
                        title="Route"
                        description="You can only send from stores at facilities you are assigned to."
                    >
                        <Field
                            label="From store"
                            htmlFor="source"
                            required
                            error={form.errors.source_warehouse_id}
                        >
                            <Select
                                value={form.data.source_warehouse_id}
                                onValueChange={(v) =>
                                    form.setData({
                                        ...form.data,
                                        source_warehouse_id: v,
                                        lines: form.data.lines.map((l) => ({
                                            ...l,
                                            lot_id: '',
                                        })),
                                    })
                                }
                            >
                                <SelectTrigger id="source" className="w-full">
                                    <SelectValue placeholder="Choose the source" />
                                </SelectTrigger>
                                <SelectContent>
                                    {sources.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.facility} · {s.badge} {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="To store"
                            htmlFor="destination"
                            required
                            error={form.errors.destination_warehouse_id}
                        >
                            <Select
                                value={form.data.destination_warehouse_id}
                                onValueChange={(v) =>
                                    form.setData('destination_warehouse_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="destination"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose the destination" />
                                </SelectTrigger>
                                <SelectContent>
                                    {destinations.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.facility} · {s.badge} {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Expected arrival"
                            htmlFor="expected_at"
                            error={form.errors.expected_at}
                        >
                            <Input
                                id="expected_at"
                                type="date"
                                min={today}
                                value={form.data.expected_at}
                                onChange={(e) =>
                                    form.setData('expected_at', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Reason"
                            htmlFor="reason"
                            error={form.errors.reason}
                        >
                            <Input
                                id="reason"
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                                placeholder="e.g. Delhi launch stock"
                            />
                        </Field>
                        <div className="flex items-start gap-3 sm:col-span-2">
                            <Checkbox
                                id="requires_inspection"
                                checked={form.data.requires_inspection}
                                onCheckedChange={(c) =>
                                    form.setData(
                                        'requires_inspection',
                                        c === true,
                                    )
                                }
                            />
                            <div className="space-y-0.5">
                                <Label htmlFor="requires_inspection">
                                    Inspect on receipt
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Received stock goes to the destination
                                    facility's quarantine store with a QC
                                    inspection instead of straight onto the
                                    shelf. Finished goods normally skip this;
                                    batches keep their QC status either way.
                                </p>
                            </div>
                        </div>
                    </FormSection>

                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">Items</h2>
                                <p className="text-muted-foreground text-xs">
                                    Quantities in the item's stock unit. Pick a
                                    batch or let approval choose by earliest
                                    expiry.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    form.setData('lines', [
                                        ...form.data.lines,
                                        {
                                            item_id: '',
                                            quantity: '',
                                            lot_id: '',
                                        },
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Add item
                            </Button>
                        </div>
                        {form.errors.lines && (
                            <p className="text-destructive px-5 pt-4 text-sm">
                                {form.errors.lines}
                            </p>
                        )}
                        <div className="divide-y">
                            {form.data.lines.map((line, i) => {
                                const item = items.find(
                                    (it) => String(it.value) === line.item_id,
                                );
                                const options = source
                                    ? (lots[`${source.id}:${line.item_id}`] ??
                                      [])
                                    : [];
                                const total = options.reduce(
                                    (c, o) => c + Number(o.available),
                                    0,
                                );
                                return (
                                    <div
                                        key={i}
                                        className="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_2fr_auto]"
                                    >
                                        <Field
                                            label="Item"
                                            htmlFor={`item-${i}`}
                                            required
                                            error={err(i, 'item_id')}
                                        >
                                            <Select
                                                value={line.item_id}
                                                onValueChange={(v) =>
                                                    setLine(i, {
                                                        item_id: v,
                                                        lot_id: '',
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`item-${i}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Choose" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {offered.map((it) => (
                                                        <SelectItem
                                                            key={it.value}
                                                            value={String(
                                                                it.value,
                                                            )}
                                                        >
                                                            {it.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <Field
                                            label={`Quantity${item?.uom ? ` (${item.uom})` : ''}`}
                                            htmlFor={`qty-${i}`}
                                            required
                                            error={err(i, 'quantity')}
                                            hint={
                                                source && line.item_id
                                                    ? `${total} free at ${source.code}`
                                                    : undefined
                                            }
                                        >
                                            <Input
                                                id={`qty-${i}`}
                                                type="number"
                                                step="any"
                                                min="0"
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        quantity:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Batch"
                                            htmlFor={`lot-${i}`}
                                            error={err(i, 'lot_id')}
                                        >
                                            <Select
                                                value={line.lot_id || 'fefo'}
                                                onValueChange={(v) =>
                                                    setLine(i, {
                                                        lot_id:
                                                            v === 'fefo'
                                                                ? ''
                                                                : v,
                                                    })
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`lot-${i}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="fefo">
                                                        Earliest expiry first
                                                    </SelectItem>
                                                    {options
                                                        .filter((o) => o.lot_id)
                                                        .map((o) => (
                                                            <SelectItem
                                                                key={o.lot_id}
                                                                value={String(
                                                                    o.lot_id,
                                                                )}
                                                            >
                                                                {o.batch} ·{' '}
                                                                {o.available}{' '}
                                                                free
                                                                {o.expiry_at
                                                                    ? ` · exp ${o.expiry_at}`
                                                                    : ''}
                                                            </SelectItem>
                                                        ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <div className="flex items-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                disabled={
                                                    form.data.lines.length === 1
                                                }
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
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            disabled={form.processing}
                            onClick={() => form.setData('submit', 'request')}
                        >
                            Raise &amp; send for approval
                        </Button>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => form.setData('submit', 'draft')}
                        >
                            Save as draft
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CreateTransfer.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stock Transfers', href: index() },
        { title: 'New transfer', href: '#' },
    ],
};
