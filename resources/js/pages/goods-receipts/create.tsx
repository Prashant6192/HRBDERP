import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { FileUp, Lock, Plus, ScanText, Trash2 } from 'lucide-react';
import { Field, FormSection } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { ItemQuickAdd } from '@/components/procurement/item-quick-add';
import { VendorQuickAdd } from '@/components/procurement/vendor-quick-add';
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
import {
    create,
    index,
    intake as intakeRoute,
    store,
} from '@/routes/goods-receipts';
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

type IntakeLine = {
    index: number;
    description: string;
    hsn: string | null;
    quantity: string | null;
    unit: string | null;
    rate: string | null;
    amount: string | null;
    batch: string | null;
    manufactured_at: string | null;
    expiry_at: string | null;
    item_id: number | null;
    item_label: string | null;
    uom_id: number | null;
};

/** The supplier's bill just uploaded: what was read and how it matched. */
type Intake = {
    token: string;
    name: string;
    mime: string;
    extraction: {
        invoice_number: string | null;
        invoice_date: string | null;
        total: string | null;
        currency: string;
        warnings: string[];
        model: string | null;
        document_type: string | null;
    };
    vendor: {
        id: number | null;
        name: string | null;
        gstin: string | null;
        matched_by: string | null;
        suggested: {
            name: string | null;
            gstin: string | null;
            address: string | null;
            phone: string | null;
            email: string | null;
        };
    };
    lines: IntakeLine[];
};

type Line = {
    item_id: string;
    quantity: string;
    uom_id: string;
    unit_price: string;
    supplier_batch_ref: string;
    manufactured_at: string;
    expiry_at: string;
    notes: string;
    intake_index: number | null;
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
    intake_index: null,
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
    presetItem,
    presetWarehouse,
    intake,
    reader,
    can,
    clients,
}: {
    vendors: SelectOption[];
    warehouses: (SelectOption & { type: string })[];
    items: ItemOption[];
    uoms: UomOption[];
    today: string;
    materialRequests: MaterialRequestOption[];
    selectedMaterialRequest: number | null;
    presetItem?: number | null;
    presetWarehouse?: number | null;
    intake: Intake | null;
    reader: { available: boolean; model: string | null };
    can: { manual: boolean; add_material: boolean };
    clients: SelectOption[];
}) {
    // Without the right to key a receipt by hand, the particulars are the
    // bill's: only the item mapping and the unit are chosen on screen.
    const locked = !can.manual;
    const pageErrors = usePage().props.errors as Record<
        string,
        string | undefined
    >;
    const [vendorOptions, setVendorOptions] = useState<SelectOption[]>(vendors);
    // Materials added from the bill without leaving the page join the list.
    const [itemOptions, setItemOptions] = useState<ItemOption[]>(items);
    const [uploading, setUploading] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    // The bill's goods lines; a freight or rounding-off line has no quantity.
    const billLines = (intake?.lines ?? []).filter((l) => l.quantity !== null);
    const unmatched = billLines.filter((l) => l.item_id === null).length;

    const lineFromBill = (l: IntakeLine): Line => {
        const item = itemOptions.find((it) => it.value === l.item_id);

        return {
            item_id: l.item_id ? String(l.item_id) : '',
            quantity: l.quantity ?? '',
            uom_id: l.uom_id
                ? String(l.uom_id)
                : item
                  ? String(item.stock_uom_id)
                  : '',
            unit_price: l.rate ?? '',
            supplier_batch_ref: l.batch ?? '',
            manufactured_at: l.manufactured_at ?? '',
            expiry_at:
                l.expiry_at ??
                (item?.shelf_life_days
                    ? addDays(today, item.shelf_life_days)
                    : ''),
            notes: l.description,
            intake_index: l.index,
        };
    };

    const form = useForm({
        vendor_id: intake?.vendor.id ? String(intake.vendor.id) : '',
        material_request_id: selectedMaterialRequest
            ? String(selectedMaterialRequest)
            : '',
        warehouse_id: String(
            presetWarehouse &&
                warehouses.some((w) => w.value === presetWarehouse)
                ? presetWarehouse
                : (warehouses[0]?.value ?? ''),
        ),
        received_at: today,
        invoice_ref: intake?.extraction.invoice_number ?? '',
        notes: '',
        post_now: true,
        intake_token: intake?.token ?? '',
        // Material a third-party client sent for their own job stays theirs.
        owner_client_id: '',
        lines:
            billLines.length > 0 ? billLines.map(lineFromBill) : [emptyLine()],
    });

    const errors = form.errors as Record<string, string | undefined>;

    // Raw materials live in a raw material store, packaging in a packaging
    // store, finished goods in a finished goods or marketplace store: the
    // store list follows what is on the lines.
    const storeTypesFor = (type: string): string[] =>
        type === 'packaging_material'
            ? ['packaging', 'general']
            : type === 'finished_good' || type === 'semi_finished'
              ? ['finished_goods', 'marketplace', 'general']
              : ['raw_material', 'general'];
    const chosenTypes = Array.from(
        new Set(
            form.data.lines
                .map(
                    (l) =>
                        itemOptions.find((it) => String(it.value) === l.item_id)
                            ?.type,
                )
                .filter((t): t is string => Boolean(t)),
        ),
    );
    const storeOptions =
        chosenTypes.length === 0
            ? warehouses
            : warehouses.filter((w) =>
                  chosenTypes.every((t) => storeTypesFor(t).includes(w.type)),
              );
    const storeKey = storeOptions.map((w) => w.value).join(',');

    useEffect(() => {
        if (
            storeOptions.length > 0 &&
            !storeOptions.some(
                (w) => String(w.value) === form.data.warehouse_id,
            )
        ) {
            form.setData('warehouse_id', String(storeOptions[0].value));
        }
        // Re-pick only when the eligible stores change.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [storeKey]);

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
        } else if (presetItem && !intake) {
            // Opened from the item's own page: start with that item on line one.
            chooseItem(0, String(presetItem));
        }
        // Only on first render: the request and item come from the URL.
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
        const item = itemOptions.find((i) => String(i.value) === itemId);
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

    const upload = (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);
        router.post(
            intakeRoute().url,
            { invoice: file },
            { forceFormData: true, onFinish: () => setUploading(false) },
        );
    };

    // The bill line a form line was filled in from, if any.
    const readFor = (line: Line): IntakeLine | undefined =>
        line.intake_index === null
            ? undefined
            : intake?.lines.find((l) => l.index === line.intake_index);

    const vendorHint = !intake
        ? undefined
        : intake.vendor.id
          ? `Matched from the bill by ${intake.vendor.matched_by === 'gstin' ? 'GSTIN' : 'name'}.`
          : intake.vendor.suggested.name
            ? `The bill is from ${intake.vendor.suggested.name}${intake.vendor.suggested.gstin ? ` (${intake.vendor.suggested.gstin})` : ''}, who is not on file yet. Add them here.`
            : 'The vendor could not be read off the bill.';

    return (
        <>
            <Head title="New goods receipt" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New goods receipt"
                    description="Upload the supplier's bill and check what was read off it. Batch numbers are generated when you post the receipt."
                />

                <section className="bg-card rounded-xl border p-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 className="flex items-center gap-2 font-semibold">
                                <ScanText className="size-4" />
                                Supplier&rsquo;s bill
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                Upload the invoice or delivery challan as a PDF
                                or a photo. The vendor, quantities, rates and
                                batch details are read off it and filled in
                                below.
                            </p>
                        </div>
                        <div>
                            <input
                                ref={fileInput}
                                type="file"
                                accept="application/pdf,image/jpeg,image/png,image/webp"
                                className="hidden"
                                onChange={(e) => {
                                    upload(e.target.files?.[0]);
                                    e.target.value = '';
                                }}
                            />
                            <Button
                                type="button"
                                variant={intake ? 'outline' : 'default'}
                                disabled={!reader.available || uploading}
                                onClick={() => fileInput.current?.click()}
                            >
                                <FileUp className="size-4" />
                                {uploading
                                    ? 'Reading the bill…'
                                    : intake
                                      ? 'Scan a different bill'
                                      : 'Upload bill'}
                            </Button>
                        </div>
                    </div>

                    {!reader.available && (
                        <p className="mt-4 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                            The bill reader is not set up on this server (no
                            Claude API key).{' '}
                            {can.manual
                                ? 'Enter the receipt by hand below.'
                                : 'Ask the plant head or an administrator to book this receipt.'}
                        </p>
                    )}
                    <InputError message={pageErrors.invoice} className="mt-3" />
                    <InputError
                        message={errors.intake_token}
                        className="mt-3"
                    />

                    {intake && (
                        <>
                            <dl className="mt-5 grid gap-4 text-sm sm:grid-cols-4">
                                <div className="min-w-0">
                                    <dt className="text-muted-foreground text-xs">
                                        File
                                    </dt>
                                    <dd
                                        className="truncate font-medium"
                                        title={intake.name}
                                    >
                                        {intake.name}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        {intake.extraction.document_type ===
                                        'proforma'
                                            ? 'Proforma no.'
                                            : intake.extraction
                                                    .document_type ===
                                                'delivery_challan'
                                              ? 'Challan no.'
                                              : 'Invoice no.'}
                                    </dt>
                                    <dd className="font-medium">
                                        {intake.extraction.invoice_number ??
                                            '—'}
                                        {(intake.extraction.document_type ===
                                            'proforma' ||
                                            intake.extraction.document_type ===
                                                'quotation') && (
                                            <StatusBadge
                                                variant="warning"
                                                className="ml-2"
                                            >
                                                {intake.extraction
                                                    .document_type ===
                                                'proforma'
                                                    ? 'Proforma'
                                                    : 'Quotation'}
                                            </StatusBadge>
                                        )}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        Invoice date
                                    </dt>
                                    <dd className="font-medium">
                                        {intake.extraction.invoice_date ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground text-xs">
                                        Bill total
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {intake.extraction.total
                                            ? `${intake.extraction.currency} ${intake.extraction.total}`
                                            : '—'}
                                    </dd>
                                </div>
                            </dl>
                            <ul className="mt-3 space-y-1 text-sm">
                                <li className="text-muted-foreground">
                                    Read by{' '}
                                    {intake.extraction.model ??
                                        reader.model ??
                                        'the bill reader'}
                                    : {billLines.length} goods line
                                    {billLines.length === 1 ? '' : 's'},{' '}
                                    {billLines.length - unmatched} matched to
                                    items
                                    {unmatched > 0
                                        ? ` — choose the item on the ${unmatched} still open`
                                        : ''}
                                    .
                                </li>
                                {intake.extraction.warnings.map(
                                    (warning, i) => (
                                        <li
                                            key={i}
                                            className="text-amber-700 dark:text-amber-300"
                                        >
                                            {warning}
                                        </li>
                                    ),
                                )}
                            </ul>
                        </>
                    )}
                </section>

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
                            hint={vendorHint}
                        >
                            <div className="flex gap-2">
                                <Select
                                    value={form.data.vendor_id}
                                    onValueChange={(v) =>
                                        form.setData('vendor_id', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="vendor_id"
                                        className="min-w-0 flex-1"
                                    >
                                        <SelectValue placeholder="Select a vendor" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {vendorOptions.map((v) => (
                                            <SelectItem
                                                key={v.value}
                                                value={String(v.value)}
                                            >
                                                {v.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <VendorQuickAdd
                                    suggested={intake?.vendor.suggested}
                                    onAdded={(vendor) => {
                                        setVendorOptions((current) => [
                                            ...current,
                                            vendor,
                                        ]);
                                        form.setData(
                                            'vendor_id',
                                            String(vendor.value),
                                        );
                                    }}
                                />
                            </div>
                        </Field>

                        <Field
                            label="Material owned by"
                            htmlFor="owner_client_id"
                            error={errors.owner_client_id}
                            hint="Client-supplied material is booked in the client's name and is never used for anyone else."
                        >
                            <Select
                                value={form.data.owner_client_id || NONE}
                                onValueChange={(v) =>
                                    form.setData(
                                        'owner_client_id',
                                        v === NONE ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="owner_client_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        Our company
                                    </SelectItem>
                                    {clients.map((c) => (
                                        <SelectItem
                                            key={c.value}
                                            value={String(c.value)}
                                        >
                                            Client: {c.label}
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
                                    {storeOptions.map((w) => (
                                        <SelectItem
                                            key={w.value}
                                            value={String(w.value)}
                                        >
                                            {w.label}
                                        </SelectItem>
                                    ))}
                                    {storeOptions.length === 0 && (
                                        <div className="text-muted-foreground px-2 py-1.5 text-sm">
                                            No store takes every item on this
                                            receipt. Book raw materials,
                                            packaging and finished goods on
                                            separate receipts.
                                        </div>
                                    )}
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
                                    {locked
                                        ? 'Quantities, rates and batch details come off the bill. Choose the item and unit where the reader could not.'
                                        : 'Batch details entered here print on the QC sticker.'}
                                </p>
                            </div>
                            {!locked && (
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
                            )}
                        </div>

                        <InputError
                            message={errors.lines}
                            className="px-5 pt-3"
                        />

                        {locked && !intake ? (
                            <div className="text-muted-foreground flex items-start gap-3 p-5 text-sm">
                                <Lock className="mt-0.5 size-4 shrink-0" />
                                <p>
                                    Upload the supplier&rsquo;s bill above; the
                                    lines are filled in from it. Only the plant
                                    head or an administrator may key a receipt
                                    in by hand.
                                </p>
                            </div>
                        ) : (
                            <div className="divide-y">
                                {form.data.lines.map((line, i) => {
                                    const item = itemOptions.find(
                                        (it) =>
                                            String(it.value) === line.item_id,
                                    );
                                    const read = readFor(line);

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
                                                        {itemOptions.map(
                                                            (it) => (
                                                                <SelectItem
                                                                    key={
                                                                        it.value
                                                                    }
                                                                    value={String(
                                                                        it.value,
                                                                    )}
                                                                >
                                                                    {it.label}
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                                {read && (
                                                    <p className="text-muted-foreground text-xs">
                                                        On the bill:{' '}
                                                        {read.description}
                                                        {read.hsn
                                                            ? ` · HSN ${read.hsn}`
                                                            : ''}
                                                        {read.unit
                                                            ? ` · ${read.unit}`
                                                            : ''}
                                                    </p>
                                                )}
                                                {read &&
                                                    !line.item_id &&
                                                    can.add_material && (
                                                        <ItemQuickAdd
                                                            suggested={{
                                                                name: read.description,
                                                                hsn: read.hsn,
                                                                uom_id: read.uom_id,
                                                                quantity:
                                                                    read.quantity,
                                                                rate: read.rate,
                                                            }}
                                                            uoms={uoms}
                                                            onAdded={(
                                                                added,
                                                            ) => {
                                                                setItemOptions(
                                                                    (list) => [
                                                                        ...list,
                                                                        added,
                                                                    ],
                                                                );
                                                                setLine(i, {
                                                                    item_id:
                                                                        String(
                                                                            added.value,
                                                                        ),
                                                                    uom_id:
                                                                        line.uom_id ||
                                                                        String(
                                                                            added.stock_uom_id,
                                                                        ),
                                                                    expiry_at:
                                                                        line.expiry_at ||
                                                                        (added.shelf_life_days &&
                                                                        form
                                                                            .data
                                                                            .received_at
                                                                            ? addDays(
                                                                                  form
                                                                                      .data
                                                                                      .received_at,
                                                                                  added.shelf_life_days,
                                                                              )
                                                                            : ''),
                                                                });
                                                            }}
                                                            trigger={
                                                                <Button
                                                                    type="button"
                                                                    variant="outline"
                                                                    size="sm"
                                                                >
                                                                    <Plus className="size-4" />
                                                                    Not on file?
                                                                    Add it as a
                                                                    new material
                                                                </Button>
                                                            }
                                                        />
                                                    )}
                                                <InputError
                                                    message={
                                                        errors[
                                                            `lines.${i}.item_id`
                                                        ]
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
                                                        disabled={locked}
                                                        value={line.quantity}
                                                        onChange={(e) =>
                                                            setLine(i, {
                                                                quantity:
                                                                    e.target
                                                                        .value,
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
                                                                    key={
                                                                        u.value
                                                                    }
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
                                                        errors[
                                                            `lines.${i}.uom_id`
                                                        ]
                                                    }
                                                />
                                            </div>

                                            <div className="space-y-2 lg:col-span-2">
                                                <span className="text-sm font-medium">
                                                    Price / unit (₹)
                                                </span>
                                                <Input
                                                    inputMode="decimal"
                                                    disabled={locked}
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
                                                    disabled={locked}
                                                    value={
                                                        line.supplier_batch_ref
                                                    }
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
                                                    disabled={locked}
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
                                                    disabled={
                                                        locked &&
                                                        !!read?.expiry_at
                                                    }
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

                                            {!locked && (
                                                <div className="flex items-end justify-end lg:col-span-12">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-muted-foreground"
                                                        disabled={
                                                            form.data.lines
                                                                .length === 1
                                                        }
                                                        onClick={() =>
                                                            form.setData(
                                                                'lines',
                                                                form.data.lines.filter(
                                                                    (_, j) =>
                                                                        j !== i,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                        Remove line
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </section>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            disabled={form.processing || (locked && !intake)}
                        >
                            Post receipt
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing || (locked && !intake)}
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
