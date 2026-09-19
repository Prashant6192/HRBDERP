import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    Flame,
    PackageCheck,
    QrCode,
    ScanLine,
    ShieldCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { ArtworkGallery } from '@/components/contract/artwork-gallery';
import { ClientBadge } from '@/components/contract/client-badge';
import { VerificationPanel } from '@/components/manufacturing/verification-panel';
import {
    ThirdPartyPanel,
    type ThirdPartyDetails,
} from '@/components/contract/third-party-panel';
import { DetailItem } from '@/components/form-field';
import {
    BatchAnalyticsPanel,
    type BatchAnalytics,
} from '@/components/manufacturing/batch-analytics';
import {
    StagePanel,
    type StageSummary,
} from '@/components/manufacturing/stage-panel';
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
import { index as approvalsIndex } from '@/routes/approvals';
import { download as downloadDocument } from '@/routes/documents';
import { show as showFormula } from '@/routes/formulas';
import { show as showLot } from '@/routes/lots';
import { issue as floorIssue } from '@/routes/floor';
import {
    approve,
    cancel,
    card,
    complete,
    index,
    show,
    start,
} from '@/routes/manufacturing';
import { show as showPlan } from '@/routes/plans';
import { show as showProduct } from '@/routes/products';
import type { Verification } from '@/pages/floor/issue';
import type {
    ArtworkRow,
    ManufacturingOrder,
    ManufacturingOrderLineRow,
    ReservationRow,
    StoreKind,
} from '@/types';

/** The account the completion form shows as it is typed. */
function reconcileDraft(
    filled: string,
    rejected: string,
    samples: string,
    plannedUnits: number | null,
): {
    good: number | null;
    packingYield: string | null;
    overallYield: string | null;
} {
    if (filled === '') {
        return { good: null, packingYield: null, overallYield: null };
    }

    const f = Number(filled);
    const good = f - Number(rejected || 0) - Number(samples || 0);

    return {
        good,
        packingYield: f > 0 ? ((good / f) * 100).toFixed(1) : null,
        overallYield:
            plannedUnits && plannedUnits > 0
                ? ((good / plannedUnits) * 100).toFixed(1)
                : null,
    };
}

function pct(value: string | null): string {
    return value === null ? '—' : `${Number(value)}%`;
}

/**
 * The batch account, read after completion: planned against made, filled
 * against kept, and where the difference went.
 */
function ReconciliationPanel({ order }: { order: ManufacturingOrder }) {
    if (!order.output_quantity) {
        return null;
    }

    const uom = order.planned_uom?.code ?? '';
    const planned = Number(order.planned_quantity);
    const made = Number(order.output_quantity);
    const leftover = order.bulk_leftover_quantity
        ? Number(order.bulk_leftover_quantity)
        : null;
    const bulkLoss = planned - made;
    const rows: { label: string; value: string; muted?: boolean }[] = [
        {
            label: 'Bulk planned',
            value: `${qty(order.planned_quantity)} ${uom}`,
        },
        { label: 'Bulk made', value: `${qty(order.output_quantity)} ${uom}` },
        {
            label: bulkLoss >= 0 ? 'Process loss' : 'Over-yield',
            value: `${qty(String(Math.abs(bulkLoss)))} ${uom} · ${pct(order.yield_percentage)} bulk yield`,
            muted: true,
        },
    ];

    if (leftover !== null) {
        rows.push({
            label: 'Bulk left unpacked',
            value: `${qty(String(leftover))} ${uom}`,
        });
    }

    if (order.planned_units) {
        rows.push({
            label: 'Units planned',
            value: order.planned_units.toLocaleString(),
        });
    }

    if (order.filled_units !== null && order.filled_units !== undefined) {
        rows.push(
            {
                label: 'Units filled',
                value: order.filled_units.toLocaleString(),
            },
            {
                label: 'Rejected at packing',
                value: `${(order.rejected_units ?? 0).toLocaleString()}${order.filled_units > 0 ? ` (${(((order.rejected_units ?? 0) / order.filled_units) * 100).toFixed(1)}%)` : ''}`,
            },
            {
                label: 'Samples kept',
                value: (order.sample_units ?? 0).toLocaleString(),
            },
        );
    }

    if (order.output_units) {
        rows.push({
            label: 'Good units to stock',
            value: `${order.output_units.toLocaleString()}${order.packing_yield_percentage ? ` · ${pct(order.packing_yield_percentage)} packing yield` : ''}${order.overall_yield_percentage ? ` · ${pct(order.overall_yield_percentage)} of plan` : ''}`,
        });
    }

    return (
        <section className="rounded-xl border">
            <div className="border-b px-5 py-3">
                <h2 className="font-medium">Batch reconciliation</h2>
                <p className="text-muted-foreground text-xs">
                    What was planned, what the kettle gave, what the line kept.
                </p>
            </div>
            <dl className="divide-y">
                {rows.map((r) => (
                    <div
                        key={r.label}
                        className="flex items-center justify-between gap-4 px-5 py-2 text-sm"
                    >
                        <dt
                            className={
                                r.muted
                                    ? 'text-muted-foreground'
                                    : 'text-muted-foreground font-medium'
                            }
                        >
                            {r.label}
                        </dt>
                        <dd className="text-right tabular-nums">{r.value}</dd>
                    </div>
                ))}
                {order.loss_notes && (
                    <div className="px-5 py-2 text-sm">
                        <dt className="text-muted-foreground">
                            Where the loss went
                        </dt>
                        <dd>{order.loss_notes}</dd>
                    </div>
                )}
            </dl>
        </section>
    );
}

function LinesTable({
    lines,
    reservations,
    status,
    clientItems = [],
}: {
    lines: ManufacturingOrderLineRow[];
    reservations: Record<string, ReservationRow[]>;
    status: ManufacturingOrder['status'];
    /** Materials the client supplies: drawn from the client's own batches. */
    clientItems?: number[];
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
                                        {clientItems.includes(l.item_id) && (
                                            <StatusBadge
                                                variant="info"
                                                className="ml-2"
                                            >
                                                Client supplied
                                            </StatusBadge>
                                        )}
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
    thirdParty,
    stages,
    approval,
    documents,
    artworks,
    verification,
    scanCode,
    analytics,
    lots,
    can,
}: {
    order: ManufacturingOrder;
    lines: ManufacturingOrderLineRow[];
    reservations: Record<string, ReservationRow[]>;
    today: string;
    thirdParty: ThirdPartyDetails | null;
    stages: StageSummary;
    approval: {
        id: number;
        requested_by: string | null;
        requested_at: string | null;
        triggers: { key: string; reason: string }[];
    } | null;
    documents: {
        id: number;
        code: string;
        version: number;
        kind: string;
        kind_label: string;
        title: string;
        effective_from: string | null;
        has_file: boolean;
    }[];
    artworks: ArtworkRow[];
    verification: Verification | null;
    scanCode: string;
    analytics: BatchAnalytics | null;
    lots: { item_id: number; lot_id: number; batch_number: string }[];
    can: {
        terms: boolean;
        approve: boolean;
        start: boolean;
        complete: boolean;
        cancel: boolean;
        stage: boolean;
        adjust: boolean;
    };
}) {
    const [completing, setCompleting] = useState(false);
    const stockedByPiece = order.product?.stock_uom?.dimension === 'count';

    const form = useForm({
        output_quantity: order.planned_quantity,
        filled_units: order.planned_units ? String(order.planned_units) : '',
        rejected_units: '0',
        sample_units: '0',
        bulk_leftover_quantity: '',
        loss_notes: '',
        manufactured_at: today,
        expiry_at: '',
        notes: '',
    });

    // The batch account as the operator types it, so the good units
    // that will go to stock are in view before the batch is posted.
    const account = reconcileDraft(
        form.data.filled_units,
        form.data.rejected_units,
        form.data.sample_units,
        order.planned_units,
    );

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
                            <ClientBadge client={order.client} />
                            <StatusBadge
                                variant={MO_STATUS_VARIANT[order.status]}
                            >
                                {MO_STATUS_LABEL[order.status]}
                            </StatusBadge>
                            <Button size="sm" variant="outline" asChild>
                                <a
                                    href={card(order.id).url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <QrCode className="size-4" />
                                    Batch card
                                </a>
                            </Button>
                            {verification && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={floorIssue(order.id)}>
                                        <ScanLine className="size-4" />
                                        Issue by scan
                                    </Link>
                                </Button>
                            )}
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
                                                <div className="space-y-3 rounded-md border p-3">
                                                    <p className="text-sm font-medium">
                                                        Packing account
                                                    </p>
                                                    <div className="grid gap-3 sm:grid-cols-3">
                                                        <div className="space-y-2">
                                                            <Label htmlFor="filled_units">
                                                                Units filled
                                                                {stockedByPiece
                                                                    ? ''
                                                                    : ' (optional)'}
                                                            </Label>
                                                            <Input
                                                                id="filled_units"
                                                                inputMode="numeric"
                                                                value={
                                                                    form.data
                                                                        .filled_units
                                                                }
                                                                onChange={(e) =>
                                                                    form.setData(
                                                                        'filled_units',
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
                                                                        .filled_units
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label htmlFor="rejected_units">
                                                                Rejected at
                                                                packing
                                                            </Label>
                                                            <Input
                                                                id="rejected_units"
                                                                inputMode="numeric"
                                                                value={
                                                                    form.data
                                                                        .rejected_units
                                                                }
                                                                onChange={(e) =>
                                                                    form.setData(
                                                                        'rejected_units',
                                                                        e.target.value.replace(
                                                                            /\D/g,
                                                                            '',
                                                                        ),
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    form.errors
                                                                        .rejected_units
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label htmlFor="sample_units">
                                                                Samples kept
                                                            </Label>
                                                            <Input
                                                                id="sample_units"
                                                                inputMode="numeric"
                                                                value={
                                                                    form.data
                                                                        .sample_units
                                                                }
                                                                onChange={(e) =>
                                                                    form.setData(
                                                                        'sample_units',
                                                                        e.target.value.replace(
                                                                            /\D/g,
                                                                            '',
                                                                        ),
                                                                    )
                                                                }
                                                            />
                                                            <InputError
                                                                message={
                                                                    form.errors
                                                                        .sample_units
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                    <p
                                                        className={
                                                            account.good !==
                                                                null &&
                                                            account.good < 1
                                                                ? 'text-destructive text-sm'
                                                                : 'text-muted-foreground text-sm'
                                                        }
                                                    >
                                                        {account.good === null
                                                            ? 'Good units to stock = filled − rejected − samples.'
                                                            : account.good < 1
                                                              ? 'Nothing is left to post to stock.'
                                                              : `${account.good.toLocaleString()} good units go to stock` +
                                                                (account.packingYield !==
                                                                null
                                                                    ? ` · packing yield ${account.packingYield}%`
                                                                    : '') +
                                                                (account.overallYield !==
                                                                null
                                                                    ? ` · ${account.overallYield}% of the ${order.planned_units?.toLocaleString()} planned`
                                                                    : '')}
                                                    </p>
                                                </div>
                                            )}

                                            <div className="grid gap-4 sm:grid-cols-2">
                                                <div className="space-y-2">
                                                    <Label htmlFor="bulk_leftover_quantity">
                                                        Bulk left unpacked (
                                                        {
                                                            order.planned_uom
                                                                ?.code
                                                        }
                                                        )
                                                    </Label>
                                                    <Input
                                                        id="bulk_leftover_quantity"
                                                        inputMode="decimal"
                                                        value={
                                                            form.data
                                                                .bulk_leftover_quantity
                                                        }
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'bulk_leftover_quantity',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            form.errors
                                                                .bulk_leftover_quantity
                                                        }
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label htmlFor="loss_notes">
                                                        Where the loss went
                                                    </Label>
                                                    <Input
                                                        id="loss_notes"
                                                        value={
                                                            form.data.loss_notes
                                                        }
                                                        placeholder="Kettle residue, leaky tubes, line trial…"
                                                        onChange={(e) =>
                                                            form.setData(
                                                                'loss_notes',
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    <InputError
                                                        message={
                                                            form.errors
                                                                .loss_notes
                                                        }
                                                    />
                                                </div>
                                            </div>

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
                                        ? ` · ${order.output_units.toLocaleString()} good units`
                                        : ''}
                                    {order.yield_percentage
                                        ? ` · ${Number(order.yield_percentage)}% bulk yield`
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

                {approval && (
                    <section className="rounded-xl border border-amber-500/30 bg-amber-500/5 p-5 text-sm">
                        <h2 className="font-semibold">
                            Release awaiting a second signature
                        </h2>
                        <p className="text-muted-foreground mt-1">
                            Raised by {approval.requested_by ?? '—'}. It is
                            decided under{' '}
                            <Link
                                href={approvalsIndex()}
                                className="underline-offset-4 hover:underline"
                            >
                                Approvals
                            </Link>
                            .
                        </p>
                        <ul className="mt-2 space-y-1">
                            {approval.triggers.map((t, i) => (
                                <li key={i} className="flex items-start gap-2">
                                    <span className="mt-2 size-1.5 shrink-0 rounded-full bg-amber-500" />
                                    <span>{t.reason}</span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {verification && (
                    <VerificationPanel
                        verification={verification}
                        scanCode={scanCode}
                        orderId={order.id}
                        status={order.status}
                    />
                )}

                {order.product && (
                    <section className="bg-card rounded-xl border p-5">
                        <h2 className="font-semibold">
                            Artwork for this batch
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {artworks.some((a) => a.status === 'approved')
                                ? 'The pack must match the approved version. Anything still awaiting approval is marked.'
                                : 'No approved artwork is on file for this product. Packaging should wait for one.'}
                        </p>
                        <ArtworkGallery
                            artworks={artworks}
                            className="mt-3"
                            emptyText="No artwork uploaded for this product yet."
                        />
                    </section>
                )}

                {documents.length > 0 && (
                    <section className="bg-card rounded-xl border p-5">
                        <h2 className="font-semibold">Controlled documents</h2>
                        <p className="text-muted-foreground text-sm">
                            The approved current versions production references
                            for this product.
                        </p>
                        <ul className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {documents.map((d) => (
                                <li
                                    key={d.id}
                                    className="rounded-lg border p-3 text-sm"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="font-mono text-xs">
                                            {d.code} v{d.version}
                                        </span>
                                        <StatusBadge variant="success">
                                            {d.kind_label}
                                        </StatusBadge>
                                    </div>
                                    <div className="mt-1 font-medium">
                                        {d.title}
                                    </div>
                                    {d.has_file && (
                                        <a
                                            href={downloadDocument(d.id).url}
                                            className="text-primary text-xs underline-offset-4 hover:underline"
                                        >
                                            Open file
                                        </a>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <StagePanel
                    orderId={order.id}
                    status={order.status}
                    stages={stages}
                    canRecord={can.stage}
                />

                {thirdParty && (
                    <ThirdPartyPanel
                        order={order}
                        details={thirdParty}
                        canTerms={can.terms}
                    />
                )}

                {order.status === 'completed' && (
                    <ReconciliationPanel order={order} />
                )}

                {analytics && (
                    <BatchAnalyticsPanel
                        orderId={order.id}
                        analytics={analytics}
                        lots={lots}
                        canAdjust={can.adjust}
                    />
                )}

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
                            clientItems={thirdParty?.client_supplied_item_ids}
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
