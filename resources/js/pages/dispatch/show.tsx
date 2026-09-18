import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    CircleAlert,
    CircleCheck,
    FileJson2,
    FileText,
    Paperclip,
    Printer,
    Truck,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem, Field } from '@/components/form-field';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TONE_VARIANT, day, rupees, when } from '@/lib/dispatch';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    cancel,
    challan,
    deliver,
    dispatch as dispatchRoute,
    index,
    invoice as invoiceRoute,
    show,
} from '@/routes/dispatches';
import { store as attachRoute } from '@/routes/dispatches/attachments';
import type { DispatchStatus, DispatchTone, SelectOption } from '@/types';

type Dispatch = {
    id: number;
    number: string;
    status: DispatchStatus;
    status_label: string;
    status_tone: DispatchTone;
    reference: string | null;
    notes: string | null;
    facility: { id: number; name: string };
    store: { id: number; code: string; name: string };
    seller: {
        gstin: string | null;
        legal_name: string;
        trade_name: string;
        address_1: string | null;
        city: string | null;
        state_code: string | null;
    };
    customer: {
        id: number;
        code: string;
        name: string;
        legal_name: string | null;
        gstin: string | null;
        kind_label: string;
        client: string | null;
        address: string;
    };
    ship_to: { name: string; gstin: string | null; address: string } | null;
    place_of_supply: string | null;
    place_of_supply_name: string | null;
    is_interstate: boolean;
    invoice_number: string | null;
    invoice_date: string | null;
    irn: string | null;
    ack_number: string | null;
    ack_date: string | null;
    has_signed_qr: boolean;
    transport: {
        transporter_name: string | null;
        transporter_gstin: string | null;
        vehicle_number: string | null;
        lr_number: string | null;
        lr_date: string | null;
        eway_bill_number: string | null;
        eway_bill_date: string | null;
        distance_km: number | null;
    };
    money: {
        taxable_value: string;
        cgst: string;
        sgst: string;
        igst: string;
        other_charges: string;
        round_off: string;
        total_value: string;
    };
    delivery_note: string | null;
    cancel_reason: string | null;
    timeline: {
        label: string;
        by: string | null;
        at: string | null;
        done: boolean;
    }[];
    cancelled_at: string | null;
};

type Line = {
    id: number;
    line_no: number;
    item_code: string;
    item_name: string;
    batch: string | null;
    expiry_at: string | null;
    owner: string;
    uom: string | null;
    quantity: string;
    unit_price: string;
    discount_percent: string;
    hsn_code: string | null;
    gst_rate: string;
    taxable_value: string;
    cgst: string;
    sgst: string;
    igst: string;
    line_total: string;
    description: string | null;
};

type Attachment = {
    id: number;
    kind: string;
    kind_label: string;
    name: string;
    size: number;
    uploaded_at: string | null;
    uploaded_by: string | null;
    url: string;
};

type EInvoice = {
    mandatory: boolean;
    requires_irn: boolean;
    ready: boolean;
    missing: string[];
    json_url: string;
};

const kb = (bytes: number) =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

export default function ShowDispatch({
    dispatch,
    lines,
    attachments,
    attachmentKinds,
    einvoice,
    today,
    can,
}: {
    dispatch: Dispatch;
    lines: Line[];
    attachments: Attachment[];
    attachmentKinds: SelectOption[];
    einvoice: EInvoice;
    today: string;
    can: {
        invoice: boolean;
        attach: boolean;
        dispatch: boolean;
        deliver: boolean;
        cancel: boolean;
        challan: boolean;
        einvoice_json: boolean;
    };
}) {
    const [invoicing, setInvoicing] = useState(
        can.invoice && dispatch.invoice_number === null,
    );
    const [leaving, setLeaving] = useState(false);

    const invoiceForm = useForm({
        invoice_number: dispatch.invoice_number ?? '',
        invoice_date: dispatch.invoice_date ?? today,
        irn: dispatch.irn ?? '',
        ack_number: dispatch.ack_number ?? '',
        ack_date: dispatch.ack_date ? dispatch.ack_date.slice(0, 10) : '',
        signed_qr: '',
    });

    const attachForm = useForm<{ kind: string; document: File | null }>({
        kind: einvoice.requires_irn ? 'signed_invoice' : 'invoice',
        document: null,
    });

    const transportForm = useForm({
        transporter_name: dispatch.transport.transporter_name ?? '',
        transporter_gstin: dispatch.transport.transporter_gstin ?? '',
        vehicle_number: dispatch.transport.vehicle_number ?? '',
        lr_number: dispatch.transport.lr_number ?? '',
        lr_date: dispatch.transport.lr_date ?? '',
        eway_bill_number: dispatch.transport.eway_bill_number ?? '',
        eway_bill_date: dispatch.transport.eway_bill_date ?? '',
        distance_km: dispatch.transport.distance_km
            ? String(dispatch.transport.distance_km)
            : '',
    });

    const post = (url: string, data: Record<string, string | undefined> = {}) =>
        router.post(url, data, { preserveScroll: true });

    const tax =
        Number(dispatch.money.cgst) +
        Number(dispatch.money.sgst) +
        Number(dispatch.money.igst);

    return (
        <>
            <Head title={dispatch.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={dispatch.number}
                    description={`To ${dispatch.customer.name}${dispatch.reference ? ` · ref ${dispatch.reference}` : ''}`}
                    actions={
                        <>
                            {can.invoice && (
                                <Button
                                    variant={invoicing ? 'outline' : 'default'}
                                    onClick={() => setInvoicing(!invoicing)}
                                >
                                    <FileText className="size-4" />
                                    {dispatch.invoice_number
                                        ? 'Edit invoice details'
                                        : 'Record invoice'}
                                </Button>
                            )}
                            {can.einvoice_json && (
                                <Button asChild variant="outline">
                                    <a href={einvoice.json_url}>
                                        <FileJson2 className="size-4" />
                                        E-invoice JSON (IRP)
                                    </a>
                                </Button>
                            )}
                            {can.challan && (
                                <Button asChild variant="outline">
                                    <a
                                        href={challan(dispatch.id).url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <Printer className="size-4" />
                                        Print challan
                                    </a>
                                </Button>
                            )}
                            {can.dispatch && (
                                <Button
                                    onClick={() => setLeaving(!leaving)}
                                    disabled={!einvoice.ready}
                                    title={
                                        einvoice.ready
                                            ? undefined
                                            : 'Not ready: see the e-invoice checklist'
                                    }
                                >
                                    <Truck className="size-4" />
                                    Dispatch goods
                                </Button>
                            )}
                            {can.deliver && (
                                <ConfirmDialog
                                    trigger={
                                        <Button>
                                            <Check className="size-4" />
                                            Mark delivered
                                        </Button>
                                    }
                                    title={`Mark ${dispatch.number} delivered?`}
                                    description="The consignment reached the customer."
                                    confirmLabel="Delivered"
                                    action={() =>
                                        post(deliver(dispatch.id).url)
                                    }
                                />
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline">
                                            <X className="size-4" />
                                            Cancel
                                        </Button>
                                    }
                                    title={`Cancel ${dispatch.number}?`}
                                    description="Nothing has left the store, so nothing is reversed. The note is closed."
                                    confirmLabel="Cancel dispatch"
                                    destructive
                                    action={() => post(cancel(dispatch.id).url)}
                                />
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant={TONE_VARIANT[dispatch.status_tone]}>
                        {dispatch.status_label}
                    </StatusBadge>
                    <StatusBadge variant="muted">
                        {dispatch.is_interstate
                            ? 'Inter-state · IGST'
                            : 'Intra-state · CGST + SGST'}
                    </StatusBadge>
                    {dispatch.irn && (
                        <StatusBadge variant="success">
                            IRN recorded
                        </StatusBadge>
                    )}
                    {dispatch.transport.vehicle_number && (
                        <StatusBadge variant="muted">
                            Vehicle {dispatch.transport.vehicle_number}
                        </StatusBadge>
                    )}
                    {dispatch.cancel_reason && (
                        <StatusBadge variant="destructive">
                            {dispatch.cancel_reason}
                        </StatusBadge>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <div className="flex flex-wrap items-start gap-4 text-sm">
                            <div className="min-w-48 flex-1">
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    From · seller
                                </p>
                                <p className="font-medium">
                                    {dispatch.seller.legal_name}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {dispatch.facility.name} ·{' '}
                                    {dispatch.store.code} {dispatch.store.name}
                                </p>
                                <p className="text-muted-foreground font-mono text-xs">
                                    {dispatch.seller.gstin
                                        ? `GSTIN ${dispatch.seller.gstin}`
                                        : 'No GSTIN on the facility'}
                                </p>
                            </div>
                            <ArrowRight className="text-muted-foreground mt-4 size-5" />
                            <div className="min-w-48 flex-1">
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    Bill to
                                </p>
                                <p className="font-medium">
                                    {dispatch.customer.legal_name ??
                                        dispatch.customer.name}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {dispatch.customer.kind_label}
                                    {dispatch.customer.client
                                        ? ` · goods of ${dispatch.customer.client}`
                                        : ''}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {dispatch.customer.address || '—'}
                                </p>
                                <p className="text-muted-foreground font-mono text-xs">
                                    {dispatch.customer.gstin
                                        ? `GSTIN ${dispatch.customer.gstin}`
                                        : 'Unregistered (B2C)'}
                                </p>
                            </div>
                            <div className="min-w-48 flex-1">
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    Ship to
                                </p>
                                {dispatch.ship_to ? (
                                    <>
                                        <p className="font-medium">
                                            {dispatch.ship_to.name}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {dispatch.ship_to.address || '—'}
                                        </p>
                                        {dispatch.ship_to.gstin && (
                                            <p className="text-muted-foreground font-mono text-xs">
                                                GSTIN {dispatch.ship_to.gstin}
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    <p className="text-muted-foreground">
                                        Customer&rsquo;s own address
                                    </p>
                                )}
                            </div>
                        </div>
                        <dl className="mt-5 grid gap-4 sm:grid-cols-3">
                            <DetailItem label="Place of supply">
                                {dispatch.place_of_supply}
                                {dispatch.place_of_supply_name
                                    ? ` — ${dispatch.place_of_supply_name}`
                                    : ''}
                            </DetailItem>
                            <DetailItem label="Invoice">
                                {dispatch.invoice_number ? (
                                    <>
                                        <span className="font-mono">
                                            {dispatch.invoice_number}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            {day(dispatch.invoice_date)}
                                        </span>
                                    </>
                                ) : (
                                    'Not recorded yet'
                                )}
                            </DetailItem>
                            <DetailItem label="Notes">
                                {dispatch.notes ?? '—'}
                            </DetailItem>
                        </dl>
                        {(dispatch.transport.transporter_name ||
                            dispatch.transport.lr_number ||
                            dispatch.transport.eway_bill_number) && (
                            <dl className="mt-4 grid gap-4 border-t pt-4 sm:grid-cols-3">
                                <DetailItem label="Transporter">
                                    {dispatch.transport.transporter_name ?? '—'}
                                    {dispatch.transport.transporter_gstin && (
                                        <span className="text-muted-foreground block font-mono text-xs">
                                            {
                                                dispatch.transport
                                                    .transporter_gstin
                                            }
                                        </span>
                                    )}
                                </DetailItem>
                                <DetailItem label="LR / docket">
                                    {dispatch.transport.lr_number ?? '—'}
                                    {dispatch.transport.lr_date && (
                                        <span className="text-muted-foreground block text-xs">
                                            {day(dispatch.transport.lr_date)}
                                        </span>
                                    )}
                                </DetailItem>
                                <DetailItem label="E-way bill">
                                    {dispatch.transport.eway_bill_number ?? '—'}
                                    {dispatch.transport.eway_bill_date && (
                                        <span className="text-muted-foreground block text-xs">
                                            {day(
                                                dispatch.transport
                                                    .eway_bill_date,
                                            )}
                                            {dispatch.transport.distance_km
                                                ? ` · ${dispatch.transport.distance_km} km`
                                                : ''}
                                        </span>
                                    )}
                                </DetailItem>
                            </dl>
                        )}
                    </section>

                    <section className="bg-card rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Progress</h2>
                        <ol className="space-y-3">
                            {dispatch.timeline.map((step) => (
                                <li
                                    key={step.label}
                                    className="flex items-start gap-3 text-sm"
                                >
                                    <span
                                        className={cn(
                                            'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border text-xs',
                                            step.done
                                                ? 'border-emerald-600 bg-emerald-600 text-white'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {step.done ? (
                                            <Check className="size-3" />
                                        ) : (
                                            ''
                                        )}
                                    </span>
                                    <div>
                                        <p
                                            className={cn(
                                                'font-medium',
                                                !step.done &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {step.label}
                                        </p>
                                        {step.done && (
                                            <p className="text-muted-foreground text-xs">
                                                {when(step.at)}
                                                {step.by ? ` · ${step.by}` : ''}
                                            </p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ol>
                        {dispatch.delivery_note && (
                            <p className="text-muted-foreground mt-4 text-xs">
                                {dispatch.delivery_note}
                            </p>
                        )}
                    </section>
                </div>

                {/* E-invoice */}
                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="font-semibold">
                                Invoice &amp; e-invoice
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                {einvoice.requires_irn
                                    ? 'E-invoicing is mandatory for this GST-registered buyer: the goods do not leave until the IRN, acknowledgement and signed invoice are on record here.'
                                    : einvoice.mandatory
                                      ? 'An unregistered buyer: no IRN applies. The plain invoice is uploaded before the goods leave.'
                                      : 'Record the invoice and keep its papers with the consignment.'}
                            </p>
                        </div>
                        <StatusBadge
                            variant={einvoice.ready ? 'success' : 'warning'}
                        >
                            {einvoice.ready
                                ? 'Ready to leave'
                                : `${einvoice.missing.length} thing${einvoice.missing.length === 1 ? '' : 's'} outstanding`}
                        </StatusBadge>
                    </div>

                    <div className="grid gap-6 p-5 lg:grid-cols-2">
                        <div className="space-y-3 text-sm">
                            {dispatch.status !== 'cancelled' &&
                                !dispatch.cancelled_at &&
                                (dispatch.status === 'draft' ||
                                    dispatch.status === 'invoiced') && (
                                    <ul className="space-y-2">
                                        <li className="flex items-center gap-2">
                                            {dispatch.invoice_number ? (
                                                <CircleCheck className="size-4 text-emerald-600" />
                                            ) : (
                                                <CircleAlert className="size-4 text-amber-600" />
                                            )}
                                            Invoice number and date recorded
                                        </li>
                                        {einvoice.requires_irn && (
                                            <li className="flex items-center gap-2">
                                                {dispatch.irn ? (
                                                    <CircleCheck className="size-4 text-emerald-600" />
                                                ) : (
                                                    <CircleAlert className="size-4 text-amber-600" />
                                                )}
                                                IRN and acknowledgement from the
                                                IRP
                                            </li>
                                        )}
                                        <li className="flex items-center gap-2">
                                            {attachments.some((a) =>
                                                einvoice.requires_irn
                                                    ? a.kind ===
                                                      'signed_invoice'
                                                    : a.kind === 'invoice' ||
                                                      a.kind ===
                                                          'signed_invoice',
                                            ) ? (
                                                <CircleCheck className="size-4 text-emerald-600" />
                                            ) : (
                                                <CircleAlert className="size-4 text-amber-600" />
                                            )}
                                            {einvoice.requires_irn
                                                ? 'Signed e-invoice (with QR) uploaded'
                                                : 'Invoice uploaded'}
                                        </li>
                                    </ul>
                                )}
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
                                <dt className="text-muted-foreground">IRN</dt>
                                <dd className="font-mono text-xs break-all">
                                    {dispatch.irn ?? '—'}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Ack no.
                                </dt>
                                <dd>
                                    {dispatch.ack_number ?? '—'}
                                    {dispatch.ack_date
                                        ? ` · ${day(dispatch.ack_date)}`
                                        : ''}
                                </dd>
                                <dt className="text-muted-foreground">
                                    Signed QR
                                </dt>
                                <dd>
                                    {dispatch.has_signed_qr
                                        ? 'Stored'
                                        : 'Not stored'}
                                </dd>
                            </dl>
                            <p className="text-muted-foreground text-xs">
                                How it works: download the e-invoice JSON,
                                upload it to the IRP (the bulk upload tool or
                                your GSP), and record the IRN, acknowledgement
                                number and date it returns. Then attach the
                                signed invoice PDF with the QR.
                            </p>
                        </div>

                        {invoicing && can.invoice && (
                            <form
                                className="grid gap-4 sm:grid-cols-2"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    invoiceForm.post(
                                        invoiceRoute(dispatch.id).url,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setInvoicing(false),
                                        },
                                    );
                                }}
                            >
                                <Field
                                    label="Invoice number"
                                    htmlFor="invoice_number"
                                    required
                                    error={invoiceForm.errors.invoice_number}
                                >
                                    <Input
                                        id="invoice_number"
                                        value={invoiceForm.data.invoice_number}
                                        className="font-mono"
                                        onChange={(e) =>
                                            invoiceForm.setData(
                                                'invoice_number',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Invoice date"
                                    htmlFor="invoice_date"
                                    required
                                    error={invoiceForm.errors.invoice_date}
                                >
                                    <Input
                                        id="invoice_date"
                                        type="date"
                                        max={today}
                                        value={invoiceForm.data.invoice_date}
                                        onChange={(e) =>
                                            invoiceForm.setData(
                                                'invoice_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="IRN (64 characters)"
                                    htmlFor="irn"
                                    required={einvoice.requires_irn}
                                    error={invoiceForm.errors.irn}
                                    className="sm:col-span-2"
                                    hint={
                                        einvoice.requires_irn
                                            ? 'As returned by the IRP.'
                                            : 'Not needed for an unregistered buyer.'
                                    }
                                >
                                    <Input
                                        id="irn"
                                        value={invoiceForm.data.irn}
                                        maxLength={64}
                                        className="font-mono text-xs"
                                        onChange={(e) =>
                                            invoiceForm.setData(
                                                'irn',
                                                e.target.value.trim(),
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Acknowledgement number"
                                    htmlFor="ack_number"
                                    error={invoiceForm.errors.ack_number}
                                >
                                    <Input
                                        id="ack_number"
                                        value={invoiceForm.data.ack_number}
                                        className="font-mono"
                                        onChange={(e) =>
                                            invoiceForm.setData(
                                                'ack_number',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Acknowledgement date"
                                    htmlFor="ack_date"
                                    error={invoiceForm.errors.ack_date}
                                >
                                    <Input
                                        id="ack_date"
                                        type="date"
                                        value={invoiceForm.data.ack_date}
                                        onChange={(e) =>
                                            invoiceForm.setData(
                                                'ack_date',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Signed QR (optional)"
                                    htmlFor="signed_qr"
                                    error={invoiceForm.errors.signed_qr}
                                    className="sm:col-span-2"
                                    hint="The signed QR string from the IRP response, if you have it."
                                >
                                    <Input
                                        id="signed_qr"
                                        value={invoiceForm.data.signed_qr}
                                        className="font-mono text-xs"
                                        onChange={(e) =>
                                            invoiceForm.setData(
                                                'signed_qr',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <div className="flex justify-end gap-2 sm:col-span-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setInvoicing(false)}
                                    >
                                        Close
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={invoiceForm.processing}
                                    >
                                        Save invoice details
                                    </Button>
                                </div>
                            </form>
                        )}
                    </div>
                </section>

                {/* Lines */}
                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">What is going</h2>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>#</TableHead>
                                <TableHead>Product</TableHead>
                                <TableHead>Batch</TableHead>
                                <TableHead>HSN</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                                <TableHead className="text-right">
                                    Rate
                                </TableHead>
                                <TableHead className="text-right">
                                    Taxable
                                </TableHead>
                                <TableHead className="text-right">
                                    GST
                                </TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((l) => (
                                <TableRow key={l.id}>
                                    <TableCell className="text-muted-foreground">
                                        {l.line_no}
                                    </TableCell>
                                    <TableCell>
                                        <div className="font-medium">
                                            {l.description ?? l.item_name}
                                        </div>
                                        <div className="text-muted-foreground font-mono text-xs">
                                            {l.item_code} · {l.owner}
                                        </div>
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {l.batch ?? '—'}
                                        {l.expiry_at && (
                                            <span className="text-muted-foreground block">
                                                exp {day(l.expiry_at)}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {l.hsn_code ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {qty(l.quantity)} {l.uom}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rupees(l.unit_price)}
                                        {Number(l.discount_percent) > 0 && (
                                            <span className="text-muted-foreground block text-xs">
                                                −{qty(l.discount_percent)}%
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rupees(l.taxable_value)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {qty(l.gst_rate)}%
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {rupees(l.line_total)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <dl className="ml-auto grid max-w-sm grid-cols-2 gap-x-6 gap-y-1 px-5 py-4 text-sm">
                        <dt className="text-muted-foreground">Taxable value</dt>
                        <dd className="text-right tabular-nums">
                            {rupees(dispatch.money.taxable_value)}
                        </dd>
                        {dispatch.is_interstate ? (
                            <>
                                <dt className="text-muted-foreground">IGST</dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(dispatch.money.igst)}
                                </dd>
                            </>
                        ) : (
                            <>
                                <dt className="text-muted-foreground">CGST</dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(dispatch.money.cgst)}
                                </dd>
                                <dt className="text-muted-foreground">SGST</dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(dispatch.money.sgst)}
                                </dd>
                            </>
                        )}
                        {Number(dispatch.money.other_charges) !== 0 && (
                            <>
                                <dt className="text-muted-foreground">
                                    Other charges
                                </dt>
                                <dd className="text-right tabular-nums">
                                    {rupees(dispatch.money.other_charges)}
                                </dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">Round off</dt>
                        <dd className="text-right tabular-nums">
                            {rupees(dispatch.money.round_off)}
                        </dd>
                        <dt className="font-semibold">Invoice total</dt>
                        <dd className="text-right text-lg font-semibold tabular-nums">
                            {rupees(dispatch.money.total_value)}
                        </dd>
                        <dt className="text-muted-foreground text-xs">
                            of which GST
                        </dt>
                        <dd className="text-muted-foreground text-right text-xs tabular-nums">
                            {rupees(tax)}
                        </dd>
                    </dl>
                </section>

                {/* Leaving */}
                {leaving && can.dispatch && (
                    <section className="bg-card rounded-xl border p-5">
                        <h2 className="font-semibold">Let the goods go</h2>
                        <p className="text-muted-foreground mb-4 text-sm">
                            The stock leaves {dispatch.store.name} the moment
                            you confirm, posted against invoice{' '}
                            {dispatch.invoice_number}. Transport details print
                            on the challan.
                        </p>
                        <form
                            className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                transportForm.post(
                                    dispatchRoute(dispatch.id).url,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setLeaving(false),
                                    },
                                );
                            }}
                        >
                            <Field
                                label="Transporter"
                                htmlFor="transporter_name"
                                error={transportForm.errors.transporter_name}
                            >
                                <Input
                                    id="transporter_name"
                                    value={transportForm.data.transporter_name}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'transporter_name',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Transporter ID (GSTIN)"
                                htmlFor="transporter_gstin"
                                error={transportForm.errors.transporter_gstin}
                            >
                                <Input
                                    id="transporter_gstin"
                                    maxLength={15}
                                    className="font-mono uppercase"
                                    value={transportForm.data.transporter_gstin}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'transporter_gstin',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Vehicle number"
                                htmlFor="vehicle_number"
                                error={transportForm.errors.vehicle_number}
                            >
                                <Input
                                    id="vehicle_number"
                                    placeholder="UK06AB1234"
                                    className="uppercase"
                                    value={transportForm.data.vehicle_number}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'vehicle_number',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="Distance (km)"
                                htmlFor="distance_km"
                                error={transportForm.errors.distance_km}
                            >
                                <Input
                                    id="distance_km"
                                    type="number"
                                    min="0"
                                    value={transportForm.data.distance_km}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'distance_km',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="LR / docket number"
                                htmlFor="lr_number"
                                error={transportForm.errors.lr_number}
                            >
                                <Input
                                    id="lr_number"
                                    value={transportForm.data.lr_number}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'lr_number',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="LR date"
                                htmlFor="lr_date"
                                error={transportForm.errors.lr_date}
                            >
                                <Input
                                    id="lr_date"
                                    type="date"
                                    value={transportForm.data.lr_date}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'lr_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="E-way bill number"
                                htmlFor="eway_bill_number"
                                error={transportForm.errors.eway_bill_number}
                                hint="12 digits, when the consignment needs one."
                            >
                                <Input
                                    id="eway_bill_number"
                                    maxLength={12}
                                    className="font-mono"
                                    value={transportForm.data.eway_bill_number}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'eway_bill_number',
                                            e.target.value.replace(/\D/g, ''),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                label="E-way bill date"
                                htmlFor="eway_bill_date"
                                error={transportForm.errors.eway_bill_date}
                            >
                                <Input
                                    id="eway_bill_date"
                                    type="date"
                                    value={transportForm.data.eway_bill_date}
                                    onChange={(e) =>
                                        transportForm.setData(
                                            'eway_bill_date',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <div className="flex justify-end gap-2 sm:col-span-2 lg:col-span-4">
                                <InputError
                                    message={
                                        (
                                            transportForm.errors as Record<
                                                string,
                                                string
                                            >
                                        ).dispatch
                                    }
                                    className="mr-auto"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setLeaving(false)}
                                >
                                    Not yet
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={transportForm.processing}
                                >
                                    <Truck className="size-4" />
                                    Confirm — goods have left
                                </Button>
                            </div>
                        </form>
                    </section>
                )}

                {/* Attachments */}
                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Papers</h2>
                        <p className="text-muted-foreground text-sm">
                            The invoice, the signed e-invoice, the e-way bill,
                            the LR — everything that went with it, kept here.
                        </p>
                    </div>
                    <ul className="divide-y">
                        {attachments.length === 0 && (
                            <li className="text-muted-foreground px-5 py-6 text-sm">
                                Nothing attached yet.
                            </li>
                        )}
                        {attachments.map((a) => (
                            <li
                                key={a.id}
                                className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm"
                            >
                                <Paperclip className="text-muted-foreground size-4" />
                                <a
                                    href={a.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {a.name}
                                </a>
                                <StatusBadge variant="muted">
                                    {a.kind_label}
                                </StatusBadge>
                                <span className="text-muted-foreground ml-auto text-xs">
                                    {kb(a.size)} · {when(a.uploaded_at)}
                                    {a.uploaded_by ? ` · ${a.uploaded_by}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {can.attach && (
                        <form
                            className="flex flex-wrap items-end gap-3 border-t px-5 py-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                attachForm.post(attachRoute(dispatch.id).url, {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        attachForm.setData('document', null),
                                });
                            }}
                        >
                            <Field
                                label="Kind"
                                htmlFor="attach_kind"
                                error={attachForm.errors.kind}
                                className="min-w-56"
                            >
                                <Select
                                    value={attachForm.data.kind}
                                    onValueChange={(v) =>
                                        attachForm.setData('kind', v)
                                    }
                                >
                                    <SelectTrigger
                                        id="attach_kind"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {attachmentKinds.map((k) => (
                                            <SelectItem
                                                key={k.value}
                                                value={String(k.value)}
                                            >
                                                {k.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                label="File (PDF or image)"
                                htmlFor="attach_file"
                                error={attachForm.errors.document}
                                className="min-w-64"
                            >
                                <Input
                                    id="attach_file"
                                    type="file"
                                    accept=".pdf,.jpg,.jpeg,.png,.webp"
                                    onChange={(e) =>
                                        attachForm.setData(
                                            'document',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                            </Field>
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={
                                    attachForm.processing ||
                                    !attachForm.data.document
                                }
                            >
                                <Paperclip className="size-4" />
                                Attach
                            </Button>
                        </form>
                    )}
                </section>

                <p className="text-muted-foreground text-xs">
                    <Link
                        href={index()}
                        className="underline-offset-4 hover:underline"
                    >
                        All dispatches
                    </Link>
                </p>
            </div>
        </>
    );
}

ShowDispatch.layout = ({ dispatch }: { dispatch: Dispatch }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Dispatches', href: index() },
        { title: dispatch.number, href: show(dispatch.id) },
    ],
});
