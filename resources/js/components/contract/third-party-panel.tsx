import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, IndianRupee, Palette } from 'lucide-react';
import { useState } from 'react';
import { ClientBadge } from '@/components/contract/client-badge';
import { DetailItem, Field } from '@/components/form-field';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
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
    MATERIAL_SOURCE_LABEL,
    money,
} from '@/lib/contract';
import { date, qty } from '@/lib/stock';
import { show as showClient } from '@/routes/clients';
import { terms as termsRoute } from '@/routes/manufacturing';
import type {
    ClientRef,
    JobCosting,
    ManufacturingOrder,
    MaterialSource,
    ReconciliationRow,
} from '@/types';

export type ThirdPartyDetails = {
    client: ClientRef;
    client_po_ref: string | null;
    required_delivery_at: string | null;
    material_source: MaterialSource | null;
    material_source_label: string | null;
    client_supplied_item_ids: number[];
    costing: JobCosting | null;
    reconciliation: ReconciliationRow[];
    artworks: {
        approved: {
            id: number;
            kind: string;
            title: string;
            version: string;
            approved_at: string | null;
        }[];
        pending: number;
    };
    qc_spec: boolean;
};

function TermsDialog({
    order,
    costing,
}: {
    order: ManufacturingOrder;
    costing: JobCosting;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        manufacturing_rate: costing.has_terms
            ? costing.terms.manufacturing_rate
            : '',
        rate_basis: costing.terms.rate_basis,
        bill_materials: costing.terms.bill_materials,
        material_markup_pct: costing.has_terms
            ? costing.terms.material_markup_pct
            : '0',
        testing: costing.has_terms ? costing.terms.testing : '',
        development: costing.has_terms ? costing.terms.development : '',
        artwork: costing.has_terms ? costing.terms.artwork : '',
        freight: costing.has_terms ? costing.terms.freight : '',
        other: costing.has_terms ? costing.terms.other : '',
        gst_rate: costing.terms.gst_rate,
        notes: costing.terms.notes ?? '',
    });

    const numberField = (
        key:
            | 'manufacturing_rate'
            | 'material_markup_pct'
            | 'testing'
            | 'development'
            | 'artwork'
            | 'freight'
            | 'other'
            | 'gst_rate',
        label: string,
        hint?: string,
    ) => (
        <Field
            label={label}
            htmlFor={`terms-${key}`}
            error={form.errors[key]}
            hint={hint}
        >
            <Input
                id={`terms-${key}`}
                inputMode="decimal"
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
        </Field>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <IndianRupee className="size-4" />
                    {costing.has_terms ? 'Edit terms' : 'Set commercial terms'}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-2xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(termsRoute(order.id).url, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                    className="space-y-4"
                >
                    <DialogHeader>
                        <DialogTitle>
                            Commercial terms · {order.number}
                        </DialogTitle>
                        <DialogDescription>
                            What the client is charged for this job. Material is
                            valued at what the batch actually consumed; the
                            client&rsquo;s own material is never charged.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {numberField(
                            'manufacturing_rate',
                            'Manufacturing charge (₹)',
                        )}
                        <Field
                            label="Charged per"
                            htmlFor="terms-basis"
                            error={form.errors.rate_basis}
                        >
                            <Select
                                value={form.data.rate_basis}
                                onValueChange={(v) =>
                                    form.setData(
                                        'rate_basis',
                                        v as 'per_quantity' | 'per_unit',
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="terms-basis"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="per_quantity">
                                        Per {order.planned_uom?.code ?? 'kg'} of
                                        bulk
                                    </SelectItem>
                                    <SelectItem value="per_unit">
                                        Per finished unit
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        <div className="flex items-center gap-2 sm:col-span-2">
                            <Checkbox
                                id="terms-bill-materials"
                                checked={form.data.bill_materials}
                                onCheckedChange={(v) =>
                                    form.setData('bill_materials', v === true)
                                }
                            />
                            <Label htmlFor="terms-bill-materials">
                                Bill our raw and packaging material to the
                                client
                            </Label>
                        </div>
                        {form.data.bill_materials &&
                            numberField(
                                'material_markup_pct',
                                'Markup on our material (%)',
                            )}
                        {numberField('testing', 'Testing charges (₹)')}
                        {numberField('development', 'Development charges (₹)')}
                        {numberField('artwork', 'Artwork charges (₹)')}
                        {numberField('freight', 'Freight (₹)')}
                        {numberField('other', 'Other charges (₹)')}
                        {numberField('gst_rate', 'GST (%)')}
                        <Field
                            label="Notes"
                            htmlFor="terms-notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="terms-notes"
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
                            Save terms
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Everything a third-party job carries beyond an own-brand batch: the
 * client and their PO, whose material it is, artwork and QC spec status,
 * what the job cost and what is charged, and the client's material
 * reconciled.
 */
export function ThirdPartyPanel({
    order,
    details,
    canTerms,
}: {
    order: ManufacturingOrder;
    details: ThirdPartyDetails;
    canTerms: boolean;
}) {
    const costing = details.costing;
    const noArtwork = details.artworks.approved.length === 0;

    return (
        <>
            <section className="bg-card rounded-xl border p-5">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 className="font-semibold">Third-party job</h2>
                    <ClientBadge client={details.client} />
                </div>
                <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <DetailItem label="Client">
                        <Link
                            href={showClient(details.client.id)}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {details.client.name}
                        </Link>
                        <span className="text-muted-foreground ml-1 font-mono text-xs">
                            {details.client.code}
                        </span>
                    </DetailItem>
                    <DetailItem label="Client PO / work order">
                        {details.client_po_ref ?? '—'}
                    </DetailItem>
                    <DetailItem label="Required delivery">
                        {date(details.required_delivery_at)}
                    </DetailItem>
                    <DetailItem label="Material">
                        {details.material_source_label ??
                            MATERIAL_SOURCE_LABEL[
                                details.material_source ?? 'company'
                            ]}
                        {details.client_supplied_item_ids.length > 0
                            ? ` · ${details.client_supplied_item_ids.length} client-supplied`
                            : ''}
                    </DetailItem>
                    <div className="sm:col-span-2">
                        <DetailItem label="Artwork">
                            {noArtwork ? (
                                <span className="inline-flex items-center gap-1 text-amber-700 dark:text-amber-300">
                                    <AlertTriangle className="size-4" />
                                    No approved artwork on file for this product
                                    {details.artworks.pending > 0
                                        ? ` (${details.artworks.pending} awaiting the client)`
                                        : ''}
                                    .
                                </span>
                            ) : (
                                <span className="inline-flex flex-wrap items-center gap-1">
                                    <Palette className="size-4" />
                                    {details.artworks.approved
                                        .map(
                                            (a) =>
                                                `${ARTWORK_KIND_LABEL[a.kind] ?? a.kind} ${a.version}`,
                                        )
                                        .join(', ')}
                                    <span className="text-muted-foreground">
                                        approved
                                    </span>
                                </span>
                            )}
                        </DetailItem>
                    </div>
                    <div className="sm:col-span-2">
                        <DetailItem label="QC">
                            {details.qc_spec
                                ? 'Client-specific limits on file; the checkpoint shows them.'
                                : 'No client-specific limits; the usual checks apply.'}
                        </DetailItem>
                    </div>
                </dl>
            </section>

            {costing && (
                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="font-semibold">
                                Job costing &amp; client billing
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                {costing.basis === 'actual'
                                    ? 'Material at what the batch actually consumed, valued at batch cost.'
                                    : 'Estimated from the recipe at standard cost until the batch is made.'}
                                {!costing.has_terms &&
                                    ' No commercial terms recorded yet.'}
                            </p>
                        </div>
                        {canTerms && (
                            <TermsDialog order={order} costing={costing} />
                        )}
                    </div>
                    <div className="grid gap-6 p-5 lg:grid-cols-2">
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Our raw material
                                </dt>
                                <dd className="tabular-nums">
                                    {money(costing.raw_material_cost)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Our packaging
                                </dt>
                                <dd className="tabular-nums">
                                    {money(costing.packaging_cost)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-t pt-2">
                                <dt className="font-medium">
                                    Our material cost
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(costing.company_material_cost)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Client-supplied material (value, not
                                    charged)
                                </dt>
                                <dd className="text-muted-foreground tabular-nums">
                                    {money(costing.client_material_value)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-t pt-2">
                                <dt className="text-muted-foreground">
                                    Margin over our material
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(costing.margin)}
                                </dd>
                            </div>
                        </dl>
                        <dl className="space-y-2 text-sm">
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Material charge
                                    {costing.terms.bill_materials
                                        ? Number(
                                              costing.terms.material_markup_pct,
                                          ) > 0
                                            ? ` (+${costing.terms.material_markup_pct}%)`
                                            : ' (at cost)'
                                        : ' (not billed)'}
                                </dt>
                                <dd className="tabular-nums">
                                    {money(costing.material_charge)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Manufacturing charge
                                    {costing.has_terms
                                        ? ` (${money(costing.terms.manufacturing_rate)} × ${qty(costing.charged_quantity)} ${costing.terms.rate_unit})`
                                        : ''}
                                </dt>
                                <dd className="tabular-nums">
                                    {money(costing.manufacturing_charge)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    Testing, development, artwork, freight,
                                    other
                                </dt>
                                <dd className="tabular-nums">
                                    {money(costing.other_charges)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-t pt-2">
                                <dt className="font-medium">
                                    Chargeable to client
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {money(costing.chargeable)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4">
                                <dt className="text-muted-foreground">
                                    GST {costing.terms.gst_rate}%
                                </dt>
                                <dd className="tabular-nums">
                                    {money(costing.gst)}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 border-t pt-2 text-base">
                                <dt className="font-semibold">Total billing</dt>
                                <dd className="font-semibold tabular-nums">
                                    {money(costing.total)}
                                </dd>
                            </div>
                        </dl>
                    </div>
                    {costing.terms.notes && (
                        <p className="text-muted-foreground border-t px-5 py-3 text-sm">
                            {costing.terms.notes}
                        </p>
                    )}
                </section>
            )}

            {details.reconciliation.length > 0 && (
                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">
                            Client material reconciliation
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            What {details.client.name} supplied, what this job
                            took, and what remains with us.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Material</TableHead>
                                    <TableHead className="text-right">
                                        Supplied
                                    </TableHead>
                                    <TableHead className="text-right">
                                        This job
                                    </TableHead>
                                    <TableHead className="text-right">
                                        All jobs
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
                                {details.reconciliation.map((m) => (
                                    <TableRow key={m.item_id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {m.item_name}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {m.item_code}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(m.supplied)} {m.uom}
                                        </TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">
                                            {qty(m.consumed_on_job)}
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
                </section>
            )}
            <StatusBadge className="hidden" variant="muted">
                third party
            </StatusBadge>
        </>
    );
}
