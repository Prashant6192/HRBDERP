import { Head, Link, router } from '@inertiajs/react';
import { FileDown, PackagePlus, XCircle } from 'lucide-react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    PMR_STATUS_LABEL,
    PMR_STATUS_VARIANT,
    STORE_LABEL,
} from '@/lib/planning';
import { ALERT_LABEL, ALERT_VARIANT, date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import {
    create as createReceipt,
    show as showReceipt,
} from '@/routes/goods-receipts';
import { cancel, index, pdf, show } from '@/routes/material-requests';
import { show as showPlan } from '@/routes/plans';
import type { MaterialRequest, MaterialRequestLineRow } from '@/types';

export default function ShowMaterialRequest({
    request,
    lines,
    can,
}: {
    request: MaterialRequest;
    lines: MaterialRequestLineRow[];
    can: { cancel: boolean; receive: boolean; print: boolean };
}) {
    const toOrder = lines.filter((l) => Number(l.to_order) > 0);
    const outstanding = toOrder.filter((l) => !l.covered).length;

    return (
        <>
            <Head title={request.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={request.number}
                    description={`${STORE_LABEL[request.store_kind]} · ${request.warehouse?.code ?? ''} ${request.warehouse?.name ?? ''}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                variant={PMR_STATUS_VARIANT[request.status]}
                            >
                                {PMR_STATUS_LABEL[request.status]}
                            </StatusBadge>
                            {can.print && (
                                <Button asChild variant="outline" size="sm">
                                    <a
                                        href={pdf(request.id).url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <FileDown className="size-4" />
                                        Print PMR
                                    </a>
                                </Button>
                            )}
                            {can.receive && (
                                <Button asChild size="sm">
                                    <Link
                                        href={createReceipt({
                                            query: {
                                                material_request: request.id,
                                            },
                                        })}
                                    >
                                        <PackagePlus className="size-4" />
                                        Book in delivery
                                    </Link>
                                </Button>
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            <XCircle className="size-4" />
                                            Cancel
                                        </Button>
                                    }
                                    title={`Cancel ${request.number}?`}
                                    description="The request is closed without being fulfilled. The plan is not affected."
                                    confirmLabel="Cancel request"
                                    destructive
                                    action={() =>
                                        router.post(
                                            cancel(request.id).url,
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
                            {request.plan ? (
                                <Link
                                    href={showPlan(request.plan.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {request.plan.number}
                                </Link>
                            ) : (
                                '—'
                            )}
                        </DetailItem>
                        <DetailItem label="Formula / product">
                            {request.plan?.formula?.name ?? '—'}
                            {request.plan?.product && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {request.plan.product.name}
                                </span>
                            )}
                        </DetailItem>
                        <DetailItem label="Batch">
                            {request.plan
                                ? `${qty(request.plan.planned_quantity)} ${request.plan.planned_uom?.code ?? ''}${
                                      request.plan.planned_units
                                          ? ` · ${request.plan.planned_units.toLocaleString()} units`
                                          : ''
                                  }`
                                : '—'}
                        </DetailItem>
                        <DetailItem label="Needed by">
                            {date(request.needed_by)}
                        </DetailItem>
                        <DetailItem label="Raised">
                            {date(request.requested_at)}
                            {request.requested_by
                                ? ` · ${request.requested_by.name}`
                                : ''}
                        </DetailItem>
                        <DetailItem label="To order">
                            {toOrder.length === 0
                                ? 'Nothing — all in store'
                                : `${toOrder.length} line${toOrder.length === 1 ? '' : 's'}, ${outstanding} still outstanding`}
                        </DetailItem>
                        <DetailItem label="Deliveries">
                            {request.goods_receipts &&
                            request.goods_receipts.length > 0
                                ? request.goods_receipts.map((g, i) => (
                                      <span key={g.id}>
                                          {i > 0 && ', '}
                                          <Link
                                              href={showReceipt(g.id)}
                                              className="underline-offset-4 hover:underline"
                                          >
                                              {g.number}
                                          </Link>
                                      </span>
                                  ))
                                : '—'}
                        </DetailItem>
                    </dl>
                </section>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Lines</h2>
                        <p className="text-muted-foreground text-sm">
                            &ldquo;To order&rdquo; is the shortfall for this
                            batch; &ldquo;restock&rdquo; also brings the store
                            back to its reorder level.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">#</TableHead>
                                    <TableHead>Material</TableHead>
                                    <TableHead className="text-right">
                                        Required
                                    </TableHead>
                                    <TableHead className="text-right">
                                        In store
                                    </TableHead>
                                    <TableHead className="text-right">
                                        To order
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Restock
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Received
                                    </TableHead>
                                    <TableHead>Level</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lines.map((l) => (
                                    <TableRow
                                        key={l.id}
                                        className={
                                            Number(l.to_order) > 0 && !l.covered
                                                ? 'bg-red-500/5'
                                                : undefined
                                        }
                                    >
                                        <TableCell className="text-muted-foreground">
                                            {l.line_no}
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-medium">
                                                {l.item_name}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {l.item_code}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(l.required)} {l.uom}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(l.available)} {l.uom}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(l.to_order) > 0 ? (
                                                <span className="font-semibold text-red-700 dark:text-red-300">
                                                    {qty(l.to_order)} {l.uom}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(l.restock) > 0
                                                ? `${qty(l.restock)} ${l.uom}`
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(l.received) > 0
                                                ? `${qty(l.received)} ${l.uom}`
                                                : '—'}
                                            {l.covered &&
                                                Number(l.to_order) > 0 && (
                                                    <StatusBadge
                                                        variant="success"
                                                        className="ml-2"
                                                    >
                                                        Covered
                                                    </StatusBadge>
                                                )}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                variant={
                                                    ALERT_VARIANT[l.alert_level]
                                                }
                                            >
                                                {ALERT_LABEL[l.alert_level]}
                                            </StatusBadge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>
        </>
    );
}

ShowMaterialRequest.layout = ({ request }: { request: MaterialRequest }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Material requests', href: index() },
        { title: request.number, href: show(request.id) },
    ],
});
