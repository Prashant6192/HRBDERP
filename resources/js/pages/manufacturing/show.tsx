import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    Flame,
    PackageCheck,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
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
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    MO_STATUS_LABEL,
    MO_STATUS_VARIANT,
    STORE_LABEL,
} from '@/lib/planning';
import { date, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { show as showFormula } from '@/routes/formulas';
import { show as showLot } from '@/routes/lots';
import {
    approve,
    cancel,
    complete,
    index,
    show,
    start,
} from '@/routes/manufacturing';
import { show as showPlan } from '@/routes/plans';
import { show as showProduct } from '@/routes/products';
import type {
    ManufacturingOrder,
    ManufacturingOrderLineRow,
    ReservationRow,
    StoreKind,
} from '@/types';

function LinesTable({
    lines,
    reservations,
    status,
}: {
    lines: ManufacturingOrderLineRow[];
    reservations: Record<string, ReservationRow[]>;
    status: ManufacturingOrder['status'];
}) {
    if (lines.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-4 text-sm">
                No lines for this store.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-10">#</TableHead>
                        <TableHead>Material</TableHead>
                        <TableHead className="text-right">Planned</TableHead>
                        <TableHead className="text-right">Held</TableHead>
                        <TableHead className="text-right">Used</TableHead>
                        <TableHead>Batches drawn</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {lines.map((l) => {
                        const held = reservations[String(l.item_id)] ?? [];

                        return (
                            <TableRow key={l.id}>
                                <TableCell className="text-muted-foreground">
                                    {l.line_no}
                                </TableCell>
                                <TableCell>
                                    <div className="font-medium">
                                        {l.item_name}
                                    </div>
                                    <div className="text-muted-foreground text-xs">
                                        {l.item_code}
                                        {l.percentage
                                            ? ` · ${Number(l.percentage)}%`
                                            : l.is_qs
                                              ? ' · QS'
                                              : ''}
                                    </div>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {l.as_required
                                        ? 'as required'
                                        : `${qty(l.planned)} ${l.uom}`}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {status === 'draft' || l.as_required
                                        ? '—'
                                        : `${qty(l.reserved)} ${l.uom}`}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {Number(l.consumed) > 0
                                        ? `${qty(l.consumed)} ${l.uom}`
                                        : '—'}
                                </TableCell>
                                <TableCell className="text-muted-foreground text-xs">
                                    {held.length === 0
                                        ? '—'
                                        : held.map((r) => (
                                              <div key={r.id}>
                                                  {r.lot ?? 'unbatched'} ·{' '}
                                                  {qty(r.quantity)} {l.uom}
                                                  {r.status === 'consumed'
                                                      ? ' · used'
                                                      : r.status === 'released'
                                                        ? ' · released'
                                                        : ''}
                                                  {r.expiry_at
                                                      ? ` · exp ${date(r.expiry_at)}`
                                                      : ''}
                                              </div>
                                          ))}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

export default function ShowManufacturingOrder({
    order,
    lines,
    reservations,
    today,
    can,
}: {
    order: ManufacturingOrder;
    lines: ManufacturingOrderLineRow[];
    reservations: Record<string, ReservationRow[]>;
    today: string;
    can: {
        approve: boolean;
        start: boolean;
        complete: boolean;
        cancel: boolean;
    };
}) {
    const [completing, setCompleting] = useState(false);
    const stockedByPiece = order.product?.stock_uom?.dimension === 'count';

    const form = useForm({
        output_quantity: order.planned_quantity,
        output_units: order.planned_units ? String(order.planned_units) : '',
        manufactured_at: today,
        expiry_at: '',
        notes: '',
    });

    const byStore = (kind: StoreKind) =>
        lines.filter((l) => l.store_kind === kind);

    const submitComplete = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(complete(order.id).url, {
            preserveScroll: true,
            onSuccess: () => setCompleting(false),
        });
    };

    return (
        <>
            <Head title={order.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={order.number}
                    description={`${order.formula?.name ?? ''} · ${qty(order.planned_quantity)} ${order.planned_uom?.code ?? ''}${
                        order.planned_units
                            ? ` · ${order.planned_units.toLocaleString()} units`
                            : ''
                    }`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                variant={MO_STATUS_VARIANT[order.status]}
                            >
                                {MO_STATUS_LABEL[order.status]}
                            </StatusBadge>
                            {can.approve && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm">
                                            <ShieldCheck className="size-4" />
                                            Approve &amp; reserve
                                        </Button>
                                    }
                                    title="Approve this order?"
                                    description="Every material is held for it in its store, drawing on the earliest-expiring approved batches. If any material is short, nothing is held and you are told what is missing."
                                    confirmLabel="Approve"
                                    action={() =>
                                        router.post(
                                            approve(order.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            )}
                            {can.start && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm">
                                            <Flame className="size-4" />
                                            Start batch
                                        </Button>
                                    }
                                    title="Start the batch?"
                                    description="The held raw materials are issued from the store to the kettle and written off the ledger. This cannot be undone."
                                    confirmLabel="Start"
                                    action={() =>
                                        router.post(
                                            start(order.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            )}
                            {can.complete && (
                                <Dialog
                                    open={completing}
                                    onOpenChange={setCompleting}
                                >
                                    <DialogTrigger asChild>
                                        <Button size="sm">
                                            <PackageCheck className="size-4" />
                                            Complete batch
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <form
                                            onSubmit={submitComplete}
                                            className="space-y-4"
                                        >
                                            <DialogHeader>
                                                <DialogTitle>
                                                    Complete {order.number}
                                                </DialogTitle>
                                                <DialogDescription>
                                                    Packaging held for the order
                                                    is used up, and the finished
                                                    batch is posted
                                                    {order.product?.requires_qc
                                                        ? ' to quarantine for QC.'
                                                        : ' to the finished goods store.'}
                                                </DialogDescription>
                                            </DialogHeader>

                                            <div className="space-y-2">
                                                <Label htmlFor="output_quantity">
                                                    Bulk output (
                                                    {order.planned_uom?.code})
                                                </Label>
                                                <Input
                                                    id="output_quantity"
                                                    inputMode="decimal"
                                                    value={
                                                        form.data
                                                            .output_quantity
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'output_quantity',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                                <InputError
                                                    message={
                                                        form.errors
                                                            .output_quantity
                                                    }
                                                />
                                            </div>

                                            {order.product && (
                                                <div className="space-y-2">
                                                    <Label htmlFor="output_units">
                                                        Units packed
                                                        {stockedByPiece
                                                            ? ''
                                                            : ' (optional)'}
                                                    </Label>
                                                    <Input
                                                        id="output_units"
                                                        inputMode="numeric"
                                                        value={
                                                            form.data
                                                                .output_units
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'output_units',
                                                                e.target.value.replace(
                                                                    /\D/g,
                                                                    '',
                                                                ),
                                                            )
                                                        }
                                                        required={
                                                            stockedByPiece
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            form.errors
                                                                .output_units
                                                        }
                                                    />
                                                </div>
                                            )}

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="manufactured_at">
                                                        Manufactured on
                                                    </Label>
                                                    <Input
                                                        id="manufactured_at"
                                                        type="date"
                                                        max={today}
                                                        value={
                                                            form.data
                                                                .manufactured_at
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'manufactured_at',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            form.errors
                                                                .manufactured_at
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="expiry_at">
                                                        Expiry
                                                    </Label>
                                                    <Input
                                                        id="expiry_at"
                                                        type="date"
                                                        value={
                                                            form.data.expiry_at
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'expiry_at',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <p className="text-muted-foreground text-xs">
                                                        {order.product
                                                            ?.shelf_life_days
                                                            ? `Blank = ${order.product.shelf_life_days} days from manufacture.`
                                                            : 'Optional.'}
                                                    </p>
                                                    <InputError
                                                        message={
                                                            form.errors
                                                                .expiry_at
                                                        }
                                                    />
                                                </div>
                                            </div>

                                            <div className="space-y-2">
                                                <Label htmlFor="notes">
                                                    Batch notes
                                                </Label>
                                                <Input
                                                    id="notes"
                                                    value={form.data.notes}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'notes',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>

                                            <DialogFooter>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setCompleting(false)
                                                    }
                                                >
                                                    Back
                                                </Button>
                                                <Button
                                                    type="submit"
                                                    disabled={form.processing}
                                                >
                                                    <CheckCircle2 className="size-4" />
                                                    Post finished batch
                                                </Button>
                                            </DialogFooter>
                                        </form>
                                    </DialogContent>
                                </Dialog>
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            <XCircle className="size-4" />
                                            Cancel
                                        </Button>
                                    }
                                    title={`Cancel ${order.number}?`}
                                    description={
                                        order.status === 'in_progress'
                                            ? 'Raw materials already issued stay consumed; anything still held is released.'
                                            : 'Every material held for this order is released back to its store.'
                                    }
                                    confirmLabel="Cancel order"
                                    destructive
                                    action={() =>
                                        router.post(
                                            cancel(order.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            )}
                        </div>
                    }
                />

                <section className="bg-card rounded-xl border p-5">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <DetailItem label="Plan">
                            {order.plan ? (
                                <Link
                                    href={showPlan(order.plan.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {order.plan.number}
                                </Link>
                            ) : (
                                '—'
                            )}
                        </DetailItem>
                        <DetailItem label="Recipe">
                            {order.formula && (
                                <Link
                                    href={showFormula(order.formula.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {order.formula.code}
                                </Link>
                            )}{' '}
                            <span className="text-muted-foreground">
                                v{order.formula_version?.version_number}
                            </span>
                        </DetailItem>
                        <DetailItem label="Product">
                            {order.product ? (
                                <Link
                                    href={showProduct(order.product.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {order.product.name}
                                </Link>
                            ) : (
                                'Not linked — no batch will be posted'
                            )}
                        </DetailItem>
                        <DetailItem label="Opened">
                            {date(order.created_at)}
                            {order.created_by
                                ? ` · ${order.created_by.name}`
                                : ''}
                        </DetailItem>
                        <DetailItem label="Approved">
                            {order.approved_at
                                ? `${date(order.approved_at)}${order.approved_by ? ` · ${order.approved_by.name}` : ''}`
                                : '—'}
                        </DetailItem>
                        <DetailItem label="Started">
                            {date(order.started_at)}
                        </DetailItem>
                        <DetailItem label="Completed">
                            {order.completed_at
                                ? `${date(order.completed_at)}${order.completed_by ? ` · ${order.completed_by.name}` : ''}`
                                : '—'}
                        </DetailItem>
                        <DetailItem label="Output">
                            {order.output_quantity ? (
                                <>
                                    {qty(order.output_quantity)}{' '}
                                    {order.planned_uom?.code}
                                    {order.output_units
                                        ? ` · ${order.output_units.toLocaleString()} units`
                                        : ''}
                                    {order.yield_percentage
                                        ? ` · ${Number(order.yield_percentage)}% yield`
                                        : ''}
                                </>
                            ) : (
                                '—'
                            )}
                        </DetailItem>
                        {order.output_lot && (
                            <div className="sm:col-span-2">
                                <DetailItem label="Finished batch">
                                    <Link
                                        href={showLot(order.output_lot.id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {order.output_lot.batch_number}
                                    </Link>
                                    <StatusBadge
                                        variant={
                                            QC_VARIANT[
                                                order.output_lot.qc_status
                                            ]
                                        }
                                        className="ml-2"
                                    >
                                        {QC_LABEL[order.output_lot.qc_status]}
                                    </StatusBadge>
                                    {order.output_lot.expiry_at && (
                                        <span className="text-muted-foreground ml-2 text-sm">
                                            expires{' '}
                                            {date(order.output_lot.expiry_at)}
                                        </span>
                                    )}
                                </DetailItem>
                            </div>
                        )}
                        {order.notes && (
                            <div className="sm:col-span-2">
                                <DetailItem label="Notes">
                                    <span className="whitespace-pre-line">
                                        {order.notes}
                                    </span>
                                </DetailItem>
                            </div>
                        )}
                    </dl>
                </section>

                {(['raw_material', 'packaging'] as StoreKind[]).map((kind) => (
                    <section key={kind} className="bg-card rounded-xl border">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                {STORE_LABEL[kind]}
                            </h2>
                            <p className="text-muted-foreground text-sm">
                                {kind === 'raw_material'
                                    ? 'Held on approval, issued to the kettle when the batch starts.'
                                    : 'Held on approval, used when the batch is completed and packed.'}
                            </p>
                        </div>
                        <LinesTable
                            lines={byStore(kind)}
                            reservations={reservations}
                            status={order.status}
                        />
                    </section>
                ))}
            </div>
        </>
    );
}

ShowManufacturingOrder.layout = ({ order }: { order: ManufacturingOrder }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Manufacturing', href: index() },
        { title: order.number, href: show(order.id) },
    ],
});
