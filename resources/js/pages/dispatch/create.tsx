import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Field, FormSection } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SearchableSelect } from '@/components/ui/searchable-select';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { rupees } from '@/lib/dispatch';
import { dashboard } from '@/routes';
import {
    create,
    index,
    lots as lotsRoute,
    store as storeRoute,
} from '@/routes/dispatches';

type StoreOption = {
    id: number;
    code: string;
    name: string;
    facility_id: number;
    facility: string | null;
    bills_as: string;
    seller_gstin: string | null;
    seller_state_code: string | null;
};

type ShipTo = {
    name: string | null;
    gstin: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    pincode: string | null;
};

type CustomerOption = {
    id: number;
    code: string;
    name: string;
    kind: string;
    kind_label: string;
    gstin: string | null;
    state_code: string | null;
    client_id: number | null;
    client: string | null;
    shipping: ShipTo;
};

type ItemOption = {
    value: number;
    label: string;
    name: string;
    hsn_code: string | null;
    gst_rate: string | null;
    mrp: string | null;
    uom: string | null;
    client_id: number | null;
    client: string | null;
};

type LotOption = {
    lot_id: number;
    batch: string;
    expiry_at: string | null;
    available: string;
    owner_client_id: number | null;
    owner: string | null;
};

type Line = {
    item_id: string;
    lot_id: string;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    gst_rate: string;
    hsn_code: string;
    description: string;
};

const emptyLine = (): Line => ({
    item_id: '',
    lot_id: '',
    quantity: '',
    unit_price: '',
    discount_percent: '',
    gst_rate: '',
    hsn_code: '',
    description: '',
});

const blankShipTo = {
    name: '',
    gstin: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    state: '',
    pincode: '',
};

export default function CreateDispatch({
    stores,
    customers,
    items,
    states,
    einvoiceMandatory,
    brand,
    preset,
}: {
    stores: StoreOption[];
    customers: CustomerOption[];
    items: ItemOption[];
    states: { value: string; label: string }[];
    einvoiceMandatory: boolean;
    brand: string;
    preset: { warehouse_id: number | null; customer_id: number | null };
    today: string;
}) {
    const form = useForm<{
        warehouse_id: string;
        customer_id: string;
        reference: string;
        place_of_supply: string;
        other_charges: string;
        notes: string;
        ship_to: typeof blankShipTo;
        lines: Line[];
    }>({
        warehouse_id:
            preset.warehouse_id !== null
                ? String(preset.warehouse_id)
                : stores.length === 1
                  ? String(stores[0].id)
                  : '',
        customer_id:
            preset.customer_id !== null ? String(preset.customer_id) : '',
        reference: '',
        place_of_supply: '',
        other_charges: '',
        notes: '',
        ship_to: { ...blankShipTo },
        lines: [emptyLine()],
    });
    const [shipElsewhere, setShipElsewhere] = useState(false);
    const [lots, setLots] = useState<Record<string, LotOption[]>>({});

    const store = stores.find((s) => String(s.id) === form.data.warehouse_id);
    const customer = customers.find(
        (c) => String(c.id) === form.data.customer_id,
    );

    const buyerState = customer?.state_code ?? form.data.place_of_supply;
    const interstate =
        !!store?.seller_state_code &&
        !!buyerState &&
        store.seller_state_code !== buyerState;

    // Fetch the batches a store can send of each chosen item.
    useEffect(() => {
        if (!store) return;
        const wanted = form.data.lines
            .map((l) => l.item_id)
            .filter((id) => id && !lots[`${store.id}:${id}`]);
        wanted.forEach((itemId) => {
            fetch(lotsRoute({ query: { item: itemId, store: store.id } }).url, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => r.json())
                .then((data: LotOption[]) =>
                    setLots((prev) => ({
                        ...prev,
                        [`${store.id}:${itemId}`]: data,
                    })),
                )
                .catch(() => undefined);
        });
    }, [form.data.lines, store, lots]);

    const itemChoices = useMemo(
        () =>
            items.map((it) => ({
                value: String(it.value),
                label: it.label,
                hint: it.client
                    ? `Made for ${it.client}`
                    : `${brand}${it.mrp ? ` · MRP ${rupees(it.mrp)}` : ''}`,
            })),
        [items, brand],
    );

    const customerChoices = useMemo(
        () =>
            customers.map((c) => ({
                value: String(c.id),
                label: `${c.name} (${c.code})`,
                hint: `${c.kind_label}${c.client ? ` · goods of ${c.client}` : ''}${c.gstin ? ` · ${c.gstin}` : ' · unregistered'}`,
            })),
        [customers],
    );

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );
    const err = (i: number, f: string) =>
        (form.errors as Record<string, string>)[`lines.${i}.${f}`];

    const chooseItem = (i: number, itemId: string) => {
        const it = items.find((x) => String(x.value) === itemId);
        setLine(i, {
            item_id: itemId,
            lot_id: '',
            hsn_code: it?.hsn_code ?? '',
            gst_rate: it?.gst_rate ? String(Number(it.gst_rate)) : '',
        });
    };

    const chooseCustomer = (id: string) => {
        const c = customers.find((x) => String(x.id) === id);
        form.setData({
            ...form.data,
            customer_id: id,
            place_of_supply: c?.state_code ? '' : form.data.place_of_supply,
            ship_to: c
                ? {
                      name: c.shipping.name ?? '',
                      gstin: c.shipping.gstin ?? '',
                      address_line_1: c.shipping.address_line_1 ?? '',
                      address_line_2: c.shipping.address_line_2 ?? '',
                      city: c.shipping.city ?? '',
                      state: c.shipping.state ?? '',
                      pincode: c.shipping.pincode ?? '',
                  }
                : { ...blankShipTo },
        });
    };

    // A running total, so the note can be checked against the invoice
    // before it is saved. The server works it out again from scratch.
    const totals = useMemo(() => {
        let taxable = 0;
        let tax = 0;
        form.data.lines.forEach((l) => {
            const qty = Number(l.quantity) || 0;
            const price = Number(l.unit_price) || 0;
            const disc = Number(l.discount_percent) || 0;
            const rate = Number(l.gst_rate) || 0;
            const t = qty * price * (1 - disc / 100);
            taxable += t;
            tax += (t * rate) / 100;
        });
        const other = Number(form.data.other_charges) || 0;
        const gross = taxable + tax + other;
        return {
            taxable,
            tax,
            other,
            total: Math.round(gross),
            roundOff: Math.round(gross) - gross,
        };
    }, [form.data.lines, form.data.other_charges]);

    const submit = () => {
        form.transform((data) => ({
            ...data,
            ship_to: shipElsewhere ? data.ship_to : null,
            place_of_supply: customer?.state_code
                ? null
                : data.place_of_supply || null,
        }));
        form.post(storeRoute().url, { preserveScroll: true });
    };

    return (
        <>
            <Head title="New dispatch" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="New dispatch"
                    description="Write up what is leaving, from which finished goods store, to whom. Nothing moves until the invoice is recorded and the goods are let go."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        submit();
                    }}
                    className="space-y-6"
                >
                    <FormSection
                        title="Consignment"
                        description="Goods leave from a finished goods store only. A client's batches can only go to that client; ours can go to anyone."
                    >
                        <Field
                            label="From store"
                            htmlFor="warehouse_id"
                            required
                            error={form.errors.warehouse_id}
                            hint={
                                store
                                    ? `Bills as ${store.bills_as}${store.seller_gstin ? ` · GSTIN ${store.seller_gstin}` : ' · no GSTIN on the facility'}`
                                    : 'Only finished goods stores at facilities you are assigned to are offered.'
                            }
                        >
                            <Select
                                value={form.data.warehouse_id}
                                onValueChange={(v) =>
                                    form.setData({
                                        ...form.data,
                                        warehouse_id: v,
                                        lines: form.data.lines.map((l) => ({
                                            ...l,
                                            lot_id: '',
                                        })),
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="warehouse_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose the finished goods store" />
                                </SelectTrigger>
                                <SelectContent>
                                    {stores.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.facility} · {s.code} {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Bill to"
                            htmlFor="customer_id"
                            required
                            error={form.errors.customer_id}
                            hint={
                                customer
                                    ? customer.gstin
                                        ? `GST-registered in state ${customer.state_code}${einvoiceMandatory ? ' · e-invoice (IRN) required before it leaves' : ''}`
                                        : 'Unregistered buyer (B2C): no IRN, the plain invoice is uploaded instead'
                                    : 'Add customers under Dispatch → Customers.'
                            }
                        >
                            <SearchableSelect
                                id="customer_id"
                                value={form.data.customer_id}
                                onValueChange={chooseCustomer}
                                options={customerChoices}
                                placeholder="Choose the customer"
                                searchPlaceholder="Search customers…"
                            />
                        </Field>

                        {customer && !customer.gstin && (
                            <Field
                                label="Place of supply"
                                htmlFor="place_of_supply"
                                required
                                error={form.errors.place_of_supply}
                                hint="The state the goods are delivered in. It decides whether IGST or CGST + SGST applies."
                            >
                                <SearchableSelect
                                    id="place_of_supply"
                                    value={form.data.place_of_supply}
                                    onValueChange={(v) =>
                                        form.setData('place_of_supply', v)
                                    }
                                    options={states}
                                    placeholder="Choose a state"
                                    searchPlaceholder="Search states…"
                                />
                            </Field>
                        )}

                        <Field
                            label="Customer reference / PO"
                            htmlFor="reference"
                            error={form.errors.reference}
                        >
                            <Input
                                id="reference"
                                value={form.data.reference}
                                onChange={(e) =>
                                    form.setData('reference', e.target.value)
                                }
                                placeholder="PO/2026/0042"
                            />
                        </Field>

                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={form.errors.notes}
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

                        {store && customer && buyerState && (
                            <div className="sm:col-span-2">
                                <StatusBadge
                                    variant={interstate ? 'info' : 'muted'}
                                >
                                    {interstate
                                        ? `Inter-state supply (${store.seller_state_code} → ${buyerState}): IGST`
                                        : `Intra-state supply (${buyerState}): CGST + SGST`}
                                </StatusBadge>
                            </div>
                        )}
                    </FormSection>

                    <FormSection
                        title="Deliver to"
                        description="Where the goods physically go. Left alone, they go to the customer's own address."
                    >
                        <div className="flex items-center gap-2 sm:col-span-2">
                            <Checkbox
                                id="ship_elsewhere"
                                checked={shipElsewhere}
                                onCheckedChange={(v) =>
                                    setShipElsewhere(v === true)
                                }
                            />
                            <Label htmlFor="ship_elsewhere">
                                Deliver to a different consignee or address
                            </Label>
                        </div>
                        {shipElsewhere && (
                            <>
                                <Field
                                    label="Consignee name"
                                    htmlFor="ship_name"
                                    error={form.errors['ship_to.name' as never]}
                                >
                                    <Input
                                        id="ship_name"
                                        value={form.data.ship_to.name}
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                name: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Consignee GSTIN"
                                    htmlFor="ship_gstin"
                                    error={
                                        form.errors['ship_to.gstin' as never]
                                    }
                                >
                                    <Input
                                        id="ship_gstin"
                                        value={form.data.ship_to.gstin}
                                        maxLength={15}
                                        className="font-mono uppercase"
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                gstin: e.target.value.toUpperCase(),
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Address line 1"
                                    htmlFor="ship_a1"
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="ship_a1"
                                        value={form.data.ship_to.address_line_1}
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                address_line_1: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Address line 2"
                                    htmlFor="ship_a2"
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="ship_a2"
                                        value={form.data.ship_to.address_line_2}
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                address_line_2: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="City" htmlFor="ship_city">
                                    <Input
                                        id="ship_city"
                                        value={form.data.ship_to.city}
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                city: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="State" htmlFor="ship_state">
                                    <Input
                                        id="ship_state"
                                        value={form.data.ship_to.state}
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                state: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                                <Field label="PIN code" htmlFor="ship_pin">
                                    <Input
                                        id="ship_pin"
                                        value={form.data.ship_to.pincode}
                                        onChange={(e) =>
                                            form.setData('ship_to', {
                                                ...form.data.ship_to,
                                                pincode: e.target.value,
                                            })
                                        }
                                    />
                                </Field>
                            </>
                        )}
                    </FormSection>

                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">What is going</h2>
                                <p className="text-muted-foreground text-sm">
                                    Product, batch and quantity, priced as the
                                    invoice will carry it. Only QC-released
                                    batches with free stock are offered.
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
                            message={form.errors.lines}
                            className="px-5 pt-3"
                        />

                        <div className="divide-y">
                            {form.data.lines.map((line, i) => {
                                const options = store
                                    ? (lots[`${store.id}:${line.item_id}`] ??
                                      [])
                                    : [];
                                const lot = options.find(
                                    (o) => String(o.lot_id) === line.lot_id,
                                );
                                const item = items.find(
                                    (it) => String(it.value) === line.item_id,
                                );
                                const wrongOwner =
                                    lot &&
                                    lot.owner_client_id !== null &&
                                    customer &&
                                    customer.client_id !== lot.owner_client_id;
                                const qty = Number(line.quantity) || 0;
                                const taxable =
                                    qty *
                                    (Number(line.unit_price) || 0) *
                                    (1 -
                                        (Number(line.discount_percent) || 0) /
                                            100);

                                return (
                                    <div
                                        key={i}
                                        className="grid gap-4 p-5 lg:grid-cols-12"
                                    >
                                        <Field
                                            label="Product"
                                            htmlFor={`item-${i}`}
                                            required
                                            error={err(i, 'item_id')}
                                            className="lg:col-span-4"
                                        >
                                            <SearchableSelect
                                                id={`item-${i}`}
                                                value={line.item_id}
                                                onValueChange={(v) =>
                                                    chooseItem(i, v)
                                                }
                                                options={itemChoices}
                                                placeholder="Choose a product"
                                                searchPlaceholder="Search by code or name…"
                                            />
                                        </Field>
                                        <Field
                                            label="Batch"
                                            htmlFor={`lot-${i}`}
                                            required
                                            error={err(i, 'lot_id')}
                                            className="lg:col-span-3"
                                            hint={
                                                lot
                                                    ? `${lot.available} ${item?.uom ?? ''} free · ${lot.owner}${lot.expiry_at ? ` · exp ${lot.expiry_at}` : ''}`
                                                    : store && line.item_id
                                                      ? options.length === 0
                                                          ? `Nothing released in ${store.code}`
                                                          : undefined
                                                      : 'Choose the store and product first'
                                            }
                                        >
                                            <Select
                                                value={line.lot_id}
                                                onValueChange={(v) =>
                                                    setLine(i, { lot_id: v })
                                                }
                                                disabled={
                                                    !store || !line.item_id
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`lot-${i}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Choose a batch" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {options.map((o) => (
                                                        <SelectItem
                                                            key={o.lot_id}
                                                            value={String(
                                                                o.lot_id,
                                                            )}
                                                        >
                                                            {o.batch} ·{' '}
                                                            {o.available} free
                                                            {o.owner_client_id
                                                                ? ` · ${o.owner}`
                                                                : ''}
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
                                            className="lg:col-span-2"
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
                                            label="Unit price (₹)"
                                            htmlFor={`price-${i}`}
                                            required
                                            error={err(i, 'unit_price')}
                                            className="lg:col-span-2"
                                            hint={
                                                item?.mrp
                                                    ? `MRP ${rupees(item.mrp)}`
                                                    : undefined
                                            }
                                        >
                                            <Input
                                                id={`price-${i}`}
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                value={line.unit_price}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        unit_price:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <div className="flex items-end justify-end lg:col-span-1">
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
                                        <Field
                                            label="Discount %"
                                            htmlFor={`disc-${i}`}
                                            error={err(i, 'discount_percent')}
                                            className="lg:col-span-2"
                                        >
                                            <Input
                                                id={`disc-${i}`}
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                value={line.discount_percent}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        discount_percent:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="GST %"
                                            htmlFor={`gst-${i}`}
                                            error={err(i, 'gst_rate')}
                                            className="lg:col-span-2"
                                        >
                                            <Input
                                                id={`gst-${i}`}
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                value={line.gst_rate}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        gst_rate:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="HSN"
                                            htmlFor={`hsn-${i}`}
                                            error={err(i, 'hsn_code')}
                                            className="lg:col-span-2"
                                        >
                                            <Input
                                                id={`hsn-${i}`}
                                                value={line.hsn_code}
                                                className="font-mono"
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        hsn_code:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Description on invoice"
                                            htmlFor={`desc-${i}`}
                                            error={err(i, 'description')}
                                            className="lg:col-span-4"
                                        >
                                            <Input
                                                id={`desc-${i}`}
                                                value={line.description}
                                                placeholder={item?.name ?? ''}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        description:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <div className="flex flex-col items-end justify-end text-right lg:col-span-2">
                                            <span className="text-muted-foreground text-xs">
                                                Taxable
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {rupees(taxable)}
                                            </span>
                                        </div>
                                        {wrongOwner && (
                                            <p className="text-destructive flex items-center gap-2 text-sm lg:col-span-12">
                                                <AlertTriangle className="size-4" />
                                                Batch {lot.batch} belongs to{' '}
                                                {lot.owner}; it can only be
                                                dispatched to the customer
                                                linked to them.
                                            </p>
                                        )}
                                    </div>
                                );
                            })}
                        </div>

                        <div className="grid gap-4 border-t p-5 sm:grid-cols-2">
                            <Field
                                label="Other charges (₹)"
                                htmlFor="other_charges"
                                error={form.errors.other_charges}
                                hint="Freight or packing charged on the invoice, if any."
                            >
                                <Input
                                    id="other_charges"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={form.data.other_charges}
                                    onChange={(e) =>
                                        form.setData(
                                            'other_charges',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-sm sm:justify-self-end">
                                <dt className="text-muted-foreground">
                                    Taxable value
                                </dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(totals.taxable)}
                                </dd>
                                <dt className="text-muted-foreground">
                                    {interstate ? 'IGST' : 'CGST + SGST'}
                                </dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(totals.tax)}
                                </dd>
                                {totals.other > 0 && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Other charges
                                        </dt>
                                        <dd className="text-right tabular-nums">
                                            {rupees(totals.other)}
                                        </dd>
                                    </>
                                )}
                                <dt className="text-muted-foreground">
                                    Round off
                                </dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(totals.roundOff)}
                                </dd>
                                <dt className="font-semibold">Invoice total</dt>
                                <dd className="text-right text-lg font-semibold tabular-nums">
                                    {rupees(totals.total)}
                                </dd>
                            </dl>
                        </div>
                    </section>

                    <div className="flex justify-end gap-2">
                        <Button
                            type="submit"
                            disabled={form.processing || !store || !customer}
                        >
                            Save dispatch note
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

CreateDispatch.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Dispatches', href: index() },
        { title: 'New', href: create() },
    ],
};
