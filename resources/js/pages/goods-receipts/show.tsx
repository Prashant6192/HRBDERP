import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, XCircle } from 'lucide-react';
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
import { date, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { cancel, index, post, show } from '@/routes/goods-receipts';
import { show as showLot } from '@/routes/lots';
import { show as showQc } from '@/routes/qc';
import type { GoodsReceipt } from '@/types';

const STATUS_VARIANT = {
    draft: 'muted',
    received: 'success',
    cancelled: 'destructive',
} as const;

export default function ShowGoodsReceipt({
    receipt,
    can,
}: {
    receipt: GoodsReceipt;
    can: { post: boolean; cancel: boolean; viewQc: boolean };
}) {
    return (
        <>
            <Head title={receipt.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={receipt.number}
                    description={`Received ${date(receipt.received_at)}${receipt.vendor ? ` from ${receipt.vendor.name}` : ''}`}
                    actions={
                        <>
                            {can.post && (
                                <ConfirmDialog
                                    trigger={
                                        <Button>
                                            <CheckCircle2 className="size-4" />
                                            Post receipt
                                        </Button>
                                    }
                                    title={`Post ${receipt.number}?`}
                                    description="Batch numbers will be generated and the stock written to the ledger. Items needing QC go to quarantine; the rest go straight to the store. This cannot be undone — corrections are made with a further transaction."
                                    confirmLabel="Post"
                                    action={() =>
                                        router.post(post(receipt.id).url)
                                    }
                                />
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline">
                                            <XCircle className="size-4" />
                                            Cancel draft
                                        </Button>
                                    }
                                    title={`Cancel ${receipt.number}?`}
                                    description="The draft will be marked cancelled. No stock was created."
                                    confirmLabel="Cancel receipt"
                                    destructive
                                    action={() =>
                                        router.post(cancel(receipt.id).url)
                                    }
                                />
                            )}
                        </>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <h2 className="mb-5 font-semibold">Delivery</h2>
                        <dl className="grid gap-5 sm:grid-cols-2">
                            <DetailItem label="Vendor">
                                {receipt.vendor?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Destination store">
                                {receipt.warehouse
                                    ? `${receipt.warehouse.code} — ${receipt.warehouse.name}`
                                    : '—'}
                            </DetailItem>
                            <DetailItem label="Invoice / DC">
                                {receipt.invoice_ref ?? '—'}
                            </DetailItem>
                            <DetailItem label="Received by">
                                {receipt.received_by?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Notes">
                                {receipt.notes ?? '—'}
                            </DetailItem>
                        </dl>
                    </section>
                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Status</h2>
                        <StatusBadge variant={STATUS_VARIANT[receipt.status]}>
                            {receipt.status}
                        </StatusBadge>
                        {receipt.posted_at && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                Posted{' '}
                                {new Date(receipt.posted_at).toLocaleString()}
                            </p>
                        )}
                    </section>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Lines</h2>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Item</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>In stock unit</TableHead>
                                <TableHead>Supplier batch</TableHead>
                                <TableHead>Expiry</TableHead>
                                <TableHead>Batch no.</TableHead>
                                <TableHead>QC</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {receipt.lines?.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>
                                        <div className="font-medium">
                                            {line.item?.code}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {line.item?.name}
                                        </div>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap tabular-nums">
                                        {qty(line.quantity)} {line.uom?.code}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap tabular-nums">
                                        {qty(line.stock_quantity)}{' '}
                                        {line.item?.stock_uom?.code}
                                    </TableCell>
                                    <TableCell>
                                        {line.supplier_batch_ref ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {date(line.expiry_at)}
                                    </TableCell>
                                    <TableCell>
                                        {line.lot ? (
                                            <Link
                                                href={showLot(line.lot.id)}
                                                className="font-mono font-medium hover:underline"
                                            >
                                                {line.lot.batch_number}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                on posting
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {line.inspection ? (
                                            can.viewQc ? (
                                                <Link
                                                    href={showQc(
                                                        line.inspection.id,
                                                    )}
                                                >
                                                    <StatusBadge
                                                        variant={
                                                            QC_VARIANT[
                                                                line.inspection
                                                                    .status
                                                            ]
                                                        }
                                                    >
                                                        {
                                                            QC_LABEL[
                                                                line.inspection
                                                                    .status
                                                            ]
                                                        }
                                                    </StatusBadge>
                                                </Link>
                                            ) : (
                                                <StatusBadge
                                                    variant={
                                                        QC_VARIANT[
                                                            line.inspection
                                                                .status
                                                        ]
                                                    }
                                                >
                                                    {
                                                        QC_LABEL[
                                                            line.inspection
                                                                .status
                                                        ]
                                                    }
                                                </StatusBadge>
                                            )
                                        ) : line.lot ? (
                                            <StatusBadge variant="muted">
                                                Not required
                                            </StatusBadge>
                                        ) : line.item?.requires_qc ? (
                                            <span className="text-muted-foreground text-xs">
                                                will be inspected
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground text-xs">
                                                not required
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </>
    );
}

ShowGoodsReceipt.layout = ({ receipt }: { receipt: GoodsReceipt }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Goods receipts', href: index() },
        { title: receipt.number, href: show(receipt.id) },
    ],
});
