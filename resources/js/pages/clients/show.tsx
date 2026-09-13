import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CalendarCheck,
    CheckCircle2,
    FileText,
    Palette,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { DeleteDialog } from '@/components/confirm-dialog';
import { DetailItem, Field } from '@/components/form-field';
import InputError from '@/components/input-error';
import {
    ClientProfitabilityPanel,
    type ClientProfitability,
} from '@/components/contract/client-profitability-panel';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ARTWORK_KIND_LABEL,
    ARTWORK_STATUS_LABEL,
    ARTWORK_STATUS_VARIANT,
    money,
} from '@/lib/contract';
import { MO_STATUS_VARIANT, PLAN_STATUS_VARIANT } from '@/lib/planning';
import { date, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/clients';
import artworkRoutes from '@/routes/clients/artworks';
import qcSpecRoutes from '@/routes/clients/qc-specs';
import { show as showFormula } from '@/routes/formulas';
import { show as showLot } from '@/routes/lots';
import { show as showOrder } from '@/routes/manufacturing';
import { create as createPlan, show as showPlan } from '@/routes/plans';
import { show as showProduct } from '@/routes/products';
import type {
    ArtworkStatus,
    Client,
    ManufacturingOrderStatus,
    ProductionPlanStatus,
    QcSpecParameter,
    ReconciliationRow,
    SelectOption,
} from '@/types';

type Artwork = {
    id: number;
    product_id: number | null;
    product: string | null;
    kind: string;
    title: string;
    version: string;
    status: ArtworkStatus;
    status_label: string;
    approved_at: string | null;
    approved_by_name: string | null;
    recorded_by: string | null;
    document: { name: string | null; url: string } | null;
    notes: string | null;
};

type Spec = {
    id: number;
    product_id: number;
    product: string | null;
    product_code: string | null;
    parameters: QcSpecParameter[];
    notes: string | null;
};

type Order = {
    id: number;
    number: string;
    plan: string | null;
    product: string | null;
    facility: string | null;
    batch: string;
    status: ManufacturingOrderStatus;
    status_label: string;
    client_po_ref: string | null;
    required_delivery_at: string | null;
    completed_at: string | null;
    output_units: number | null;
    batch_number: string | null;
    qc_status: string | null;
};

type Plan = {
    id: number;
    number: string;
    product: string | null;
    batch: string;
    status: ProductionPlanStatus;
    status_label: string;
    client_po_ref: string | null;
    required_delivery_at: string | null;
    awaiting_client_material: number;
};

type FinishedGood = {
    lot_id: number;
    batch_number: string;
    product: string;
    product_code: string;
    on_hand: string;
    uom: string | null;
    qc_status: 'pending' | 'approved' | 'rejected' | 'on_hold' | 'not_required';
    expiry_at: string | null;
    manufactured_at: string | null;
    stores: string[];
};

const NONE = '__none__';

const emptyParameter = (): QcSpecParameter => ({
    name: '',
    min: '',
    max: '',
    target: '',
    unit: '',
});

function ArtworkDialog({
    clientId,
    products,
    kinds,
}: {
    clientId: number;
    products: (SelectOption & { own: boolean })[];
    kinds: string[];
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        product_id: string;
        kind: string;
        title: string;
        version: string;
        status: string;
        approved_at: string;
        approved_by_name: string;
        document: File | null;
        notes: string;
    }>({
        product_id: '',
        kind: 'label',
        title: '',
        version: 'v1',
        status: 'pending',
        approved_at: '',
        approved_by_name: '',
        document: null,
        notes: '',
    });

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <Plus className="size-4" />
                    Add artwork
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(artworkRoutes.store(clientId).url, {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => {
                                setOpen(false);
                                form.reset();
                            },
                        });
                    }}
                    className="space-y-4"
                >
                    <DialogHeader>
                        <DialogTitle>Record artwork</DialogTitle>
                        <DialogDescription>
                            A label, carton or bottle artwork version, and
                            whether the client has signed it off. Approving a
                            version supersedes the earlier approved one of the
                            same kind.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Product"
                            htmlFor="aw-product"
                            error={form.errors.product_id}
                        >
                            <Select
                                value={form.data.product_id || NONE}
                                onValueChange={(v) =>
                                    form.setData(
                                        'product_id',
                                        v === NONE ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="aw-product"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        All products
                                    </SelectItem>
                                    {products.map((p) => (
                                        <SelectItem
                                            key={p.value}
                                            value={String(p.value)}
                                        >
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Kind"
                            htmlFor="aw-kind"
                            error={form.errors.kind}
                        >
                            <Select
                                value={form.data.kind}
                                onValueChange={(v) => form.setData('kind', v)}
                            >
                                <SelectTrigger id="aw-kind" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {kinds.map((k) => (
                                        <SelectItem key={k} value={k}>
                                            {ARTWORK_KIND_LABEL[k] ?? k}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Title"
                            htmlFor="aw-title"
                            required
                            error={form.errors.title}
                        >
                            <Input
                                id="aw-title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="Front label"
                            />
                        </Field>
                        <Field
                            label="Version"
                            htmlFor="aw-version"
                            required
                            error={form.errors.version}
                        >
                            <Input
                                id="aw-version"
                                value={form.data.version}
                                onChange={(e) =>
                                    form.setData('version', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Status"
                            htmlFor="aw-status"
                            error={form.errors.status}
                        >
                            <Select
                                value={form.data.status}
                                onValueChange={(v) => form.setData('status', v)}
                            >
                                <SelectTrigger
                                    id="aw-status"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="pending">
                                        Awaiting client approval
                                    </SelectItem>
                                    <SelectItem value="approved">
                                        Approved by the client
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        {form.data.status === 'approved' && (
                            <>
                                <Field
                                    label="Approved on"
                                    htmlFor="aw-approved-at"
                                    required
                                    error={form.errors.approved_at}
                                >
                                    <Input
                                        id="aw-approved-at"
                                        type="date"
                                        value={form.data.approved_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'approved_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Approved by (client side)"
                                    htmlFor="aw-approved-by"
                                    error={form.errors.approved_by_name}
                                >
                                    <Input
                                        id="aw-approved-by"
                                        value={form.data.approved_by_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'approved_by_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </>
                        )}
                        <Field
                            label="Approval document"
                            htmlFor="aw-document"
                            error={form.errors.document}
                            hint="PDF or image, optional."
                            className="sm:col-span-2"
                        >
                            <Input
                                id="aw-document"
                                type="file"
                                accept="application/pdf,image/jpeg,image/png,image/webp"
                                onChange={(e) =>
                                    form.setData(
                                        'document',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="aw-notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="aw-notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save artwork
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function SpecDialog({
    clientId,
    products,
    existing,
    trigger,
}: {
    clientId: number;
    products: (SelectOption & { own: boolean })[];
    existing?: Spec;
    trigger: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        product_id: string;
        parameters: QcSpecParameter[];
        notes: string;
    }>({
        product_id: existing ? String(existing.product_id) : '',
        parameters:
            existing && existing.parameters.length > 0
                ? existing.parameters.map((p) => ({
                      name: p.name,
                      min: p.min ?? '',
                      max: p.max ?? '',
                      target: p.target ?? '',
                      unit: p.unit ?? '',
                  }))
                : [emptyParameter()],
        notes: existing?.notes ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const setParameter = (i: number, patch: Partial<QcSpecParameter>) =>
        form.setData(
            'parameters',
            form.data.parameters.map((p, j) =>
                j === i ? { ...p, ...patch } : p,
            ),
        );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!form.data.product_id) {
                            return;
                        }
                        form.put(
                            qcSpecRoutes.upsert({
                                client: clientId,
                                product: Number(form.data.product_id),
                            }).url,
                            {
                                preserveScroll: true,
                                onSuccess: () => setOpen(false),
                            },
                        );
                    }}
                    className="space-y-4"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {existing
                                ? `QC specification · ${existing.product}`
                                : 'QC specification'}
                        </DialogTitle>
                        <DialogDescription>
                            What this client wants checked on the product, and
                            within what limits. The QC Checkpoint shows it
                            against every batch made for them.
                        </DialogDescription>
                    </DialogHeader>
                    {!existing && (
                        <Field
                            label="Product"
                            htmlFor="spec-product"
                            required
                            error={errors.product_id}
                        >
                            <Select
                                value={form.data.product_id}
                                onValueChange={(v) =>
                                    form.setData('product_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="spec-product"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose the product" />
                                </SelectTrigger>
                                <SelectContent>
                                    {products.map((p) => (
                                        <SelectItem
                                            key={p.value}
                                            value={String(p.value)}
                                        >
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    )}
                    <div className="space-y-2">
                        <div className="grid grid-cols-12 gap-2 text-xs font-medium">
                            <span className="col-span-4">Parameter</span>
                            <span className="col-span-2">Min</span>
                            <span className="col-span-2">Max</span>
                            <span className="col-span-2">Target</span>
                            <span className="col-span-2">Unit</span>
                        </div>
                        {form.data.parameters.map((p, i) => (
                            <div key={i} className="grid grid-cols-12 gap-2">
                                <Input
                                    className="col-span-4"
                                    value={p.name}
                                    onChange={(e) =>
                                        setParameter(i, {
                                            name: e.target.value,
                                        })
                                    }
                                    placeholder="pH"
                                />
                                <Input
                                    className="col-span-2"
                                    value={p.min ?? ''}
                                    onChange={(e) =>
                                        setParameter(i, { min: e.target.value })
                                    }
                                />
                                <Input
                                    className="col-span-2"
                                    value={p.max ?? ''}
                                    onChange={(e) =>
                                        setParameter(i, { max: e.target.value })
                                    }
                                />
                                <Input
                                    className="col-span-2"
                                    value={p.target ?? ''}
                                    onChange={(e) =>
                                        setParameter(i, {
                                            target: e.target.value,
                                        })
                                    }
                                />
                                <div className="col-span-2 flex gap-1">
                                    <Input
                                        value={p.unit ?? ''}
                                        onChange={(e) =>
                                            setParameter(i, {
                                                unit: e.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        disabled={
                                            form.data.parameters.length === 1
                                        }
                                        onClick={() =>
                                            form.setData(
                                                'parameters',
                                                form.data.parameters.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                        <InputError message={errors.parameters} />
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                form.setData('parameters', [
                                    ...form.data.parameters,
                                    emptyParameter(),
                                ])
                            }
                        >
                            <Plus className="size-4" />
                            Add parameter
                        </Button>
                    </div>
                    <Field
                        label="Notes"
                        htmlFor="spec-notes"
                        error={errors.notes}
                    >
                        <Input
                            id="spec-notes"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                    </Field>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.product_id}
                        >
                            Save specification
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ShowClient({
    client,
    products,
    formulas,
    plans,
    orders,
    material,
    finishedGoods,
    costing,
    profitability,
    artworks,
    qcSpecs,
    productOptions,
    artworkKinds,
    can,
}: {
    client: Client;
    products: { id: number; code: string; name: string; is_active: boolean }[];
    formulas: {
        id: number;
        code: string;
        name: string;
        status: string;
        ownership: string;
    }[];
    plans: Plan[];
    orders: Order[];
    material: ReconciliationRow[];
    finishedGoods: FinishedGood[];
    profitability: ClientProfitability | null;
    costing: {
        jobs: number;
        chargeable: string;
        total: string;
        margin: string;
        client_material_value: string;
    };
    artworks: Artwork[];
    qcSpecs: Spec[];
    productOptions: (SelectOption & { own: boolean })[];
    artworkKinds: string[];
    can: {
        update: boolean;
        delete: boolean;
        plan: boolean;
        viewCosting: boolean;
    };
}) {
    const post = (url: string, data: Record<string, string> = {}) =>
        router.post(url, data, { preserveScroll: true });
    const openJobs = orders.filter(
        (o) => o.status !== 'completed' && o.status !== 'cancelled',
    );
    const billing = [
        client.billing_address_line_1,
        client.billing_address_line_2,
        client.billing_city,
        client.billing_state,
        client.billing_pincode,
    ]
        .filter(Boolean)
        .join(', ');
    const shipping = [
        client.shipping_address_line_1,
        client.shipping_address_line_2,
        client.shipping_city,
        client.shipping_state,
        client.shipping_pincode,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <Head title={client.code} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={client.name}
                    description={`${client.code}${client.legal_name ? ` · ${client.legal_name}` : ''}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <ActiveBadge active={client.is_active} />
                            {can.plan && client.is_active && (
                                <Button asChild size="sm">
                                    <Link
                                        href={createPlan({
                                            query: {
                                                type: 'third_party',
                                                client: client.id,
                                            },
                                        })}
                                    >
                                        <CalendarCheck className="size-4" />
                                        Plan a batch
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(client.id)}>
                                        <Pencil className="size-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <DeleteDialog
                                    url={destroy(client.id).url}
                                    label={client.code}
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            <Trash2 className="size-4" />
                                            Remove
                                        </Button>
                                    }
                                />
                            )}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <h2 className="mb-5 font-semibold">Client</h2>
                        <dl className="grid gap-5 sm:grid-cols-2">
                            <DetailItem label="Contact">
                                {client.contact_person ?? '—'}
                                {client.phone ? ` · ${client.phone}` : ''}
                                {client.email ? ` · ${client.email}` : ''}
                            </DetailItem>
                            <DetailItem label="GSTIN / PAN">
                                {client.gstin ?? '—'}
                                {client.pan ? ` · ${client.pan}` : ''}
                            </DetailItem>
                            <DetailItem label="Billing address">
                                {billing || '—'}
                            </DetailItem>
                            <DetailItem label="Shipping address">
                                {shipping ||
                                    (billing ? 'Same as billing' : '—')}
                            </DetailItem>
                            <DetailItem label="Terms">
                                {client.payment_terms_days !== null
                                    ? `${client.payment_terms_days} days`
                                    : '—'}
                                {client.credit_limit
                                    ? ` · credit ${money(client.credit_limit)}`
                                    : ''}
                            </DetailItem>
                            <DetailItem label="Agreement">
                                {client.agreement_ref ?? '—'}
                                {client.agreement_expires_at
                                    ? ` · valid until ${date(client.agreement_expires_at)}`
                                    : ''}
                            </DetailItem>
                            {client.notes && (
                                <div className="sm:col-span-2">
                                    <DetailItem label="Notes">
                                        {client.notes}
                                    </DetailItem>
                                </div>
                            )}
                        </dl>
                    </section>
                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">At a glance</h2>
                        <dl className="grid grid-cols-2 gap-4">
                            <DetailItem label="Open jobs">
                                {openJobs.length}
                            </DetailItem>
                            <DetailItem label="Products">
                                {products.length}
                            </DetailItem>
                            <DetailItem label="Finished goods here">
                                {finishedGoods.length} batch
                                {finishedGoods.length === 1 ? '' : 'es'}
                            </DetailItem>
                            <DetailItem label="Material with us">
                                {material.length} item
                                {material.length === 1 ? '' : 's'}
                            </DetailItem>
                            {can.viewCosting && (
                                <>
                                    <DetailItem label="Completed jobs billed">
                                        {money(costing.chargeable)}
                                        <span className="text-muted-foreground block text-xs">
                                            {costing.jobs} job
                                            {costing.jobs === 1
                                                ? ''
                                                : 's'} ·{' '}
                                            {money(costing.total)} incl. GST
                                        </span>
                                    </DetailItem>
                                    <DetailItem label="Margin over our material">
                                        {money(costing.margin)}
                                    </DetailItem>
                                </>
                            )}
                        </dl>
                    </section>
                </div>

                {profitability && (
                    <ClientProfitabilityPanel profitability={profitability} />
                )}

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Jobs</h2>
                        <p className="text-muted-foreground text-sm">
                            Every batch planned or made for {client.name}, in
                            the same pipeline as our own.
                        </p>
                    </div>
                    {plans.length === 0 && orders.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-6 text-sm">
                            Nothing planned yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Job</TableHead>
                                        <TableHead>Product</TableHead>
                                        <TableHead>Client PO</TableHead>
                                        <TableHead>Batch</TableHead>
                                        <TableHead>Delivery</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Output</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {plans.map((p) => (
                                        <TableRow key={`plan-${p.id}`}>
                                            <TableCell>
                                                <Link
                                                    href={showPlan(p.id)}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {p.number}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">
                                                    Plan
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {p.product ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {p.client_po_ref ?? '—'}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {p.batch}
                                            </TableCell>
                                            <TableCell>
                                                {date(p.required_delivery_at)}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap gap-1">
                                                    <StatusBadge
                                                        variant={
                                                            PLAN_STATUS_VARIANT[
                                                                p.status
                                                            ]
                                                        }
                                                    >
                                                        {p.status_label}
                                                    </StatusBadge>
                                                    {p.awaiting_client_material >
                                                        0 && (
                                                        <StatusBadge variant="warning">
                                                            Awaiting client
                                                            material
                                                        </StatusBadge>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>—</TableCell>
                                        </TableRow>
                                    ))}
                                    {orders.map((o) => (
                                        <TableRow key={`order-${o.id}`}>
                                            <TableCell>
                                                <Link
                                                    href={showOrder(o.id)}
                                                    className="font-medium underline-offset-4 hover:underline"
                                                >
                                                    {o.number}
                                                </Link>
                                                <div className="text-muted-foreground text-xs">
                                                    {o.plan ?? 'Order'}
                                                    {o.facility
                                                        ? ` · ${o.facility}`
                                                        : ''}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {o.product ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {o.client_po_ref ?? '—'}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {o.batch}
                                            </TableCell>
                                            <TableCell>
                                                {date(o.required_delivery_at)}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    variant={
                                                        MO_STATUS_VARIANT[
                                                            o.status
                                                        ]
                                                    }
                                                >
                                                    {o.status_label}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {o.batch_number ? (
                                                    <>
                                                        <span className="font-mono">
                                                            {o.batch_number}
                                                        </span>
                                                        {o.output_units
                                                            ? ` · ${o.output_units.toLocaleString()} units`
                                                            : ''}
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="bg-card rounded-xl border">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                Client material with us
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                What {client.name} sent, what production took,
                                what was lost, and what is still here. Their
                                stock is never used for anyone else.
                            </p>
                        </div>
                        {material.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">
                                No client-supplied material on file. Book it in
                                on a goods receipt with the owner set to{' '}
                                {client.name}.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Material</TableHead>
                                            <TableHead className="text-right">
                                                Supplied
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Consumed
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Wastage
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Balance
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {material.map((m) => (
                                            <TableRow key={m.item_id}>
                                                <TableCell>
                                                    <div className="font-medium">
                                                        {m.item_name}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {m.item_code} · {m.lots}{' '}
                                                        batch
                                                        {m.lots === 1
                                                            ? ''
                                                            : 'es'}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(m.supplied)} {m.uom}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(m.consumed)}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {Number(m.wastage) > 0
                                                        ? qty(m.wastage)
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="text-right font-medium tabular-nums">
                                                    {qty(m.balance)} {m.uom}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </section>

                    <section className="bg-card rounded-xl border">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                Finished goods awaiting dispatch
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                Batches made for {client.name} still in our
                                stores.
                            </p>
                        </div>
                        {finishedGoods.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">
                                Nothing in stock for this client.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Batch</TableHead>
                                            <TableHead>Product</TableHead>
                                            <TableHead className="text-right">
                                                On hand
                                            </TableHead>
                                            <TableHead>QC</TableHead>
                                            <TableHead>Store</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {finishedGoods.map((g) => (
                                            <TableRow key={g.lot_id}>
                                                <TableCell>
                                                    <Link
                                                        href={showLot(g.lot_id)}
                                                        className="font-mono font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {g.batch_number}
                                                    </Link>
                                                    <div className="text-muted-foreground text-xs">
                                                        mfd{' '}
                                                        {date(
                                                            g.manufactured_at,
                                                        )}
                                                        {g.expiry_at
                                                            ? ` · exp ${date(g.expiry_at)}`
                                                            : ''}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {g.product}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(g.on_hand)} {g.uom}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        variant={
                                                            QC_VARIANT[
                                                                g.qc_status
                                                            ]
                                                        }
                                                    >
                                                        {QC_LABEL[g.qc_status]}
                                                    </StatusBadge>
                                                </TableCell>
                                                <TableCell className="font-mono text-xs">
                                                    {g.stores.join(', ') || '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </section>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="bg-card rounded-xl border">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="flex items-center gap-2 font-semibold">
                                    <Palette className="size-4" />
                                    Artwork approvals
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Label, carton and bottle artwork, and the
                                    version the client has signed off.
                                </p>
                            </div>
                            {can.update && (
                                <ArtworkDialog
                                    clientId={client.id}
                                    products={productOptions}
                                    kinds={artworkKinds}
                                />
                            )}
                        </div>
                        {artworks.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">
                                No artwork on file.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {artworks.map((a) => (
                                    <li
                                        key={a.id}
                                        className="flex flex-wrap items-start justify-between gap-3 px-5 py-3"
                                    >
                                        <div className="min-w-0">
                                            <div className="font-medium">
                                                {a.title}{' '}
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {a.version}
                                                </span>
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {ARTWORK_KIND_LABEL[a.kind] ??
                                                    a.kind}
                                                {a.product
                                                    ? ` · ${a.product}`
                                                    : ' · all products'}
                                                {a.approved_at
                                                    ? ` · approved ${date(a.approved_at)}${a.approved_by_name ? ` by ${a.approved_by_name}` : ''}`
                                                    : ''}
                                            </div>
                                            {a.document && (
                                                <a
                                                    href={a.document.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="mt-1 inline-flex items-center gap-1 text-xs underline underline-offset-4"
                                                >
                                                    <FileText className="size-3" />
                                                    {a.document.name ??
                                                        'Document'}
                                                </a>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                variant={
                                                    ARTWORK_STATUS_VARIANT[
                                                        a.status
                                                    ]
                                                }
                                            >
                                                {ARTWORK_STATUS_LABEL[a.status]}
                                            </StatusBadge>
                                            {can.update &&
                                                a.status === 'pending' && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                post(
                                                                    artworkRoutes.status(
                                                                        {
                                                                            client: client.id,
                                                                            artwork:
                                                                                a.id,
                                                                        },
                                                                    ).url,
                                                                    {
                                                                        status: 'approved',
                                                                        approved_at:
                                                                            new Date()
                                                                                .toISOString()
                                                                                .slice(
                                                                                    0,
                                                                                    10,
                                                                                ),
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <CheckCircle2 className="size-4" />
                                                            Client approved
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                post(
                                                                    artworkRoutes.status(
                                                                        {
                                                                            client: client.id,
                                                                            artwork:
                                                                                a.id,
                                                                        },
                                                                    ).url,
                                                                    {
                                                                        status: 'rejected',
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Rejected
                                                        </Button>
                                                    </>
                                                )}
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="bg-card rounded-xl border">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">
                                    QC specifications
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Per product: what to check and the limits
                                    this client wants.
                                </p>
                            </div>
                            {can.update && (
                                <SpecDialog
                                    clientId={client.id}
                                    products={productOptions}
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            <Plus className="size-4" />
                                            Add specification
                                        </Button>
                                    }
                                />
                            )}
                        </div>
                        {qcSpecs.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">
                                No client-specific QC limits; the
                                product&rsquo;s usual checks apply.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {qcSpecs.map((s) => (
                                    <li key={s.id} className="px-5 py-3">
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="font-medium">
                                                {s.product}{' '}
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {s.product_code}
                                                </span>
                                            </div>
                                            {can.update && (
                                                <div className="flex gap-1">
                                                    <SpecDialog
                                                        clientId={client.id}
                                                        products={
                                                            productOptions
                                                        }
                                                        existing={s}
                                                        trigger={
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                            >
                                                                <Pencil className="size-4" />
                                                                Edit
                                                            </Button>
                                                        }
                                                    />
                                                    <DeleteDialog
                                                        url={
                                                            qcSpecRoutes.destroy(
                                                                {
                                                                    client: client.id,
                                                                    spec: s.id,
                                                                },
                                                            ).url
                                                        }
                                                        label={`the QC specification for ${s.product}`}
                                                        trigger={
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </div>
                                        <ul className="text-muted-foreground mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                                            {s.parameters.map((p, i) => (
                                                <li key={i}>
                                                    <span className="text-foreground font-medium">
                                                        {p.name}
                                                    </span>
                                                    {p.min !== null ||
                                                    p.max !== null
                                                        ? ` ${p.min ?? '…'} – ${p.max ?? '…'}`
                                                        : ''}
                                                    {p.target
                                                        ? ` (target ${p.target})`
                                                        : ''}
                                                    {p.unit ? ` ${p.unit}` : ''}
                                                </li>
                                            ))}
                                        </ul>
                                        {s.notes && (
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {s.notes}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="bg-card rounded-xl border">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                Products made for {client.name}
                            </h2>
                        </div>
                        {products.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">
                                None yet. Set &ldquo;Manufactured for&rdquo; on
                                the product.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {products.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <Link
                                            href={showProduct(p.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {p.name}
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">
                                                {p.code}
                                            </span>
                                        </Link>
                                        <ActiveBadge active={p.is_active} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <section className="bg-card rounded-xl border">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                Formulas tied to {client.name}
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                Client-owned and joint recipes. Made for this
                                client only; protected by the formula PIN like
                                every other.
                            </p>
                        </div>
                        {formulas.length === 0 ? (
                            <p className="text-muted-foreground px-5 py-6 text-sm">
                                None. Company formulas may still be planned for
                                this client.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {formulas.map((f) => (
                                    <li
                                        key={f.id}
                                        className="flex items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <Link
                                            href={showFormula(f.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {f.name}
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">
                                                {f.code}
                                            </span>
                                        </Link>
                                        <div className="flex gap-1">
                                            <StatusBadge variant="info">
                                                {f.ownership}
                                            </StatusBadge>
                                            <StatusBadge variant="muted">
                                                {f.status}
                                            </StatusBadge>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

ShowClient.layout = ({ client }: { client: Client }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Contract clients', href: index() },
        { title: client.code, href: show(client.id) },
    ],
});
