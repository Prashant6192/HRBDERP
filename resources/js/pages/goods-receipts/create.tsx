import { Head, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Field, FormSection } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/goods-receipts';
import type { SelectOption } from '@/types';

type ItemOption = SelectOption & {
    type: string;
    stock_uom_id: number;
    stock_uom: string | null;
    requires_qc: boolean;
    shelf_life_days: number | null;
};

type UomOption = SelectOption & { dimension: string };

type MaterialRequestOption = SelectOption & {
    warehouse_id: number;
    lines: {
        item_id: number;
        uom_id: number;
        outstanding: string;
        required: string;
    }[];
};

const NONE = '__none__';

type Line = {
    item_id: string;
    quantity: string;
    uom_id: string;
    unit_price: string;
    supplier_batch_ref: string;
    manufactured_at: string;
    expiry_at: string;
    notes: string;
};

const emptyLine = (): Line => ({
    item_id: '',
    quantity: '',
    uom_id: '',
    unit_price: '',
    supplier_batch_ref: '',
    manufactured_at: '',
    expiry_at: '',
    notes: '',
});

function addDays(iso: string, days: number): string {
    const d = new Date(iso);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

export default function CreateGoodsReceipt({
    vendors,
    warehouses,
    items,
    uoms,
    today,
    materialRequests,
    selectedMaterialRequest,
}: {
    vendors: SelectOption[];
    warehouses: (SelectOption & { type: string })[];
    items: ItemOption[];
    uoms: UomOption[];
    today: string;
    materialRequests: MaterialRequestOption[];
    selectedMaterialRequest: number | null;
}) {
    const form = useForm({
        vendor_id: '',
        material_request_id: selectedMaterialRequest
            ? String(selectedMaterialRequest)
            : '',
        warehouse_id: String(warehouses[0]?.value ?? ''),
        received_at: today,
        invoice_ref: '',
        notes: '',
        post_now: true,
        lines: [emptyLine()],
    });

    const errors = form.errors as Record<string, string | undefined>;

    // Booking in against a PMR: take its store and the lines still to come.
    const applyMaterialRequest = (id: string) => {
        const request = materialRequests.find((r) => String(r.value) === id);

        if (!request) {
            form.setData('material_request_id', '');
            return;
        }

        const outstanding = request.lines.filter(
            (l) => Number(l.outstanding) > 0,
        );
        const source = outstanding.length > 0 ? outstanding : request.lines;

        form.setData({
            ...form.data,
            material_request_id: id,
            warehouse_id: String(request.warehouse_id),
            lines: source.map((l) => ({
                ...emptyLine(),
                item_id: String(l.item_id),
                uom_id: String(l.uom_id),
                quantity: outstanding.length > 0 ? l.outstanding : l.required,
            })),
        });
    };

    useEffect(() => {
        if (selectedMaterialRequest) {
            applyMaterialRequest(String(selectedMaterialRequest));
        }
        // Only on first render: the request comes from the URL.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const setLine = (index: number, patch: Partial<Line>) => {
        form.setData(
            'lines',
            form.data.lines.map((line, i) =>
                i === index ? { ...line, ...patch } : line,
            ),
        );
    };

    const chooseItem = (index: number, itemId: string) => {
        const item = items.find((i) => String(i.value) === itemId);
        const patch: Partial<Line> = { item_id: itemId };

        if (item) {
            // Default the unit to the stock unit and suggest an expiry from the
            // shelf life; both can be overridden from the delivery note.
            patch.uom_id = String(item.stock_uom_id);

            if (item.shelf_life_days && form.data.received_at) {
                patch.expiry_at = addDays(
                    form.data.received_at,
                    item.shelf_life_days,
                );
            }
        }

        setLine(index, patch);
    };

    const submit = (postNow: boolean) => {
        form.transform((data) => ({ ...data, post_now: postNow }));
        form.post(store().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title="New goods receipt" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New goods receipt"
                    description="Enter the delivery as it appears on the note. Batch numbers are generated when you post it."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit(true);
                    }}
                    className="space-y-6"
                >
                    <FormSection title="Delivery">
                        {materialRequests.length > 0 && (
                            <Field
                                label="Against material request"
                                htmlFor="material_request_id"
                                error={errors.material_request_id}
                                hint="Choosing a PMR fills in its store and the quantities still to come."
                                className="sm:col-span-2"
                            >
                                <Select
                                    value={
                                        form.data.material_request_id || NONE
                                    }
                                    onValueChange={(v) =>
                                        applyMaterialRequest(
                                            v === NONE ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="material_request_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Not against a request" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            Not against a request
                                        </SelectItem>
                                        {materialRequests.map((r) => (
                                            <SelectItem
                                                key={r.value}
                                                value={String(r.value)}
                                            >
                                                {r.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}

                        <Field
                            label="Vendor"
                            htmlFor="vendor_id"
                            error={errors.vendor_id}
                        >
                            <Select
                                value={form.data.vendor_id}
                                onValueChange={(v) =>
                                    form.setData('vendor_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="vendor_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select a vendor" />
                                </SelectTrigger>
                                <SelectContent>
                                    {vendors.map((v) => (
                                        <SelectItem
                                            key={v.value}
                                            value={String(v.value)}
                                        >
                                            {v.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Destination store"
                            htmlFor="warehouse_id"
                            required
                            error={errors.warehouse_id}
                            hint="Where the stock goes once QC releases it."
                        >
                            <Select
                                value={form.data.warehouse_id}
                                onValueChange={(v) =>
                                    form.setData('warehouse_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="warehouse_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    {warehouses.map((w) => (
                                        <SelectItem
                                            key={w.value}
                                            value={String(w.value)}
                                        >
                                            {w.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Received on"
                            htmlFor="received_at"
                            required
                            error={errors.received_at}
                        >
                            <Input
                                id="received_at"
                                type="date"
                                value={form.data.received_at}
                                max={today}
                                onChange={(e) =>
                                    form.setData('received_at', e.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Supplier invoice / DC no."
                            htmlFor="invoice_ref"
                            error={errors.invoice_ref}
                        >
                            <Input
                                id="invoice_ref"
                                value={form.data.invoice_ref}
                                onChange={(e) =>
                                    form.setData('invoice_ref', e.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </FormSection>

                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">Lines</h2>
                                <p className="text-muted-foreground text-sm">
                                    Batch details entered here print on the QC
                                    sticker.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('lines', [
                                        ...form.data.lines,
                                        emptyLine(),
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Add line
                            </Button>
                        </div>

                        <InputError
                            message={errors.lines}
                            className="px-5 pt-3"
                        />

                        <div className="divide-y">
                            {form.data.lines.map((line, i) => {
                                const item = items.find(
                                    (it) => String(it.value) === line.item_id,
                                );

                                return (
                                    <div
                                        key={i}
                                        className="grid gap-4 p-5 lg:grid-cols-12"
                                    >
                                        <div className="space-y-2 lg:col-span-4">
                                            <div className="flex items-center justify-between">
                                                <span className="text-sm font-medium">
                                                    Item {i + 1}
                                                </span>
                                                {item && (
                                                    <StatusBadge
                                                        variant={
                                                            item.requires_qc
                                                                ? 'warning'
                                                                : 'muted'
                                                        }
                                                    >
                                                        {item.requires_qc
                                                            ? 'QC required'
                                                            : 'No QC'}
                                                    </StatusBadge>
                                                )}
                                            </div>
                                            <Select
                                                value={line.item_id}
                                                onValueChange={(v) =>
                                                    chooseItem(i, v)
                                                }
                                            >
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Select an item" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {items.map((it) => (
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
                                            <InputError
                                                message={
                                                    errors[`lines.${i}.item_id`]
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2 lg:col-span-2">
                                            <span className="text-sm font-medium">
                                                Quantity
                                            </span>
                                            <div className="flex gap-2">
                                                <Input
                                                    inputMode="decimal"
                                                    value={line.quantity}
                                                    onChange={(e) =>
                                                        setLine(i, {
                                                            quantity:
                                                                e.target.value,
                                                        })
                                                    }
                                                    placeholder="0.000"
                                                />
                                                <Select
                                                    value={line.uom_id}
                                                    onValueChange={(v) =>
                                                        setLine(i, {
                                                            uom_id: v,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger className="w-24">
                                                        <SelectValue placeholder="Unit" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {uoms.map((u) => (
                                                            <SelectItem
                                                                key={u.value}
                                                                value={String(
                                                                    u.value,
                                                                )}
                                                            >
                                                                {u.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${i}.quantity`
                                                    ] ??
                                                    errors[`lines.${i}.uom_id`]
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2 lg:col-span-2">
                                            <span className="text-sm font-medium">
                                                Price / unit (₹)
                                            </span>
                                            <Input
                                                inputMode="decimal"
                                                value={line.unit_price}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        unit_price:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${i}.unit_price`
                                                    ]
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2 lg:col-span-2">
                                            <span className="text-sm font-medium">
                                                Supplier batch
                                            </span>
                                            <Input
                                                value={line.supplier_batch_ref}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        supplier_batch_ref:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2 lg:col-span-1">
                                            <span className="text-sm font-medium">
                                                Mfg.
                                            </span>
                                            <Input
                                                type="date"
                                                value={line.manufactured_at}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        manufactured_at:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${i}.manufactured_at`
                                                    ]
                                                }
                                            />
                                        </div>

                                        <div className="space-y-2 lg:col-span-1">
                                            <span className="text-sm font-medium">
                                                Expiry
                                            </span>
                                            <Input
                                                type="date"
                                                value={line.expiry_at}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        expiry_at:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={
                                                    errors[
                                                        `lines.${i}.expiry_at`
                                                    ]
                                                }
                                            />
                                        </div>

                                        <div className="flex items-end justify-end lg:col-span-12">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="text-muted-foreground"
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
                                                Remove line
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Post receipt
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => submit(false)}
                        >
                            Save as draft
                        </Button>
                        <p className="text-muted-foreground text-sm">
                            Posting generates batch numbers and moves stock into
                            quarantine (or straight to the store for items that
                            need no QC).
                        </p>
                    </div>
                </form>
            </div>
        </>
    );
}

CreateGoodsReceipt.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Goods receipts', href: index() },
        { title: 'New', href: create() },
    ],
};
