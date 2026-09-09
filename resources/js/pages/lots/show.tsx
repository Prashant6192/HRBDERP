import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
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
import { index, show, sticker } from '@/routes/lots';
import type { InventoryLot, StockMovement } from '@/types';

export default function ShowLot({
    lot,
    movements,
    can,
}: {
    lot: InventoryLot;
    movements: StockMovement[];
    can: { sticker: boolean };
}) {
    const scale = lot.item?.stock_uom?.display_scale ?? 3;
    const unit = lot.item?.stock_uom?.code ?? '';

    return (
        <>
            <Head title={lot.batch_number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={lot.batch_number}
                    description={`${lot.item?.code} — ${lot.item?.name}`}
                    actions={
                        can.sticker && (
                            <Button asChild>
                                <a
                                    href={sticker(lot.id).url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer className="size-4" />
                                    Print sticker
                                </a>
                            </Button>
                        )
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <h2 className="mb-5 font-semibold">Batch</h2>
                        <dl className="grid gap-5 sm:grid-cols-2">
                            <DetailItem label="Initial quantity">
                                {qty(lot.initial_quantity, scale)} {unit}
                            </DetailItem>
                            <DetailItem label="Unit cost">
                                {lot.unit_cost
                                    ? `₹${qty(lot.unit_cost, 2)}`
                                    : '—'}
                            </DetailItem>
                            <DetailItem label="Supplier batch">
                                {lot.supplier_batch_ref ?? '—'}
                            </DetailItem>
                            <DetailItem label="Vendor">
                                {lot.vendor?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Received">
                                {date(lot.received_at)}
                            </DetailItem>
                            <DetailItem label="Manufactured">
                                {date(lot.manufactured_at)}
                            </DetailItem>
                            <DetailItem label="Expiry">
                                {date(lot.expiry_at)}
                            </DetailItem>
                            <DetailItem label="QC decided">
                                {lot.qc_decided_at
                                    ? `${new Date(lot.qc_decided_at).toLocaleString()}${lot.qc_decided_by ? ` by ${lot.qc_decided_by.name}` : ''}`
                                    : '—'}
                            </DetailItem>
                        </dl>
                    </section>

                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Where it is</h2>
                        <StatusBadge variant={QC_VARIANT[lot.qc_status]}>
                            {QC_LABEL[lot.qc_status]}
                        </StatusBadge>
                        <ul className="mt-4 space-y-2 text-sm">
                            {(lot.balances ?? [])
                                .filter((b) => Number(b.on_hand) > 0)
                                .map((b) => (
                                    <li
                                        key={b.id}
                                        className="flex items-center justify-between"
                                    >
                                        <span>
                                            {b.warehouse?.code}
                                            {b.warehouse?.is_quarantine && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    (quarantine)
                                                </span>
                                            )}
                                        </span>
                                        <span className="tabular-nums">
                                            {qty(b.on_hand, scale)}
                                            {Number(b.reserved) > 0 && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {qty(
                                                        b.reserved,
                                                        scale,
                                                    )}{' '}
                                                    held
                                                </span>
                                            )}
                                        </span>
                                    </li>
                                ))}
                            {(lot.balances ?? []).every(
                                (b) => Number(b.on_hand) <= 0,
                            ) && (
                                <li className="text-muted-foreground">
                                    Fully consumed
                                </li>
                            )}
                        </ul>
                    </section>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Movements</h2>
                        <p className="text-muted-foreground text-sm">
                            Every ledger line for this batch, newest first.
                        </p>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>When</TableHead>
                                <TableHead>Transaction</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Store</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                                <TableHead>Reason</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.map((m) => (
                                <TableRow key={m.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {m.transaction
                                            ? new Date(
                                                  m.transaction.transacted_at,
                                              ).toLocaleString()
                                            : '—'}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {m.transaction?.number}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {m.transaction?.type
                                            .replace(/_/g, ' ')
                                            .toLowerCase()}
                                    </TableCell>
                                    <TableCell>{m.warehouse?.code}</TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${Number(m.quantity) < 0 ? 'text-red-600 dark:text-red-400' : ''}`}
                                    >
                                        {Number(m.quantity) > 0 ? '+' : ''}
                                        {qty(m.quantity, scale)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-xs">
                                        {m.transaction?.reason ?? '—'}
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

ShowLot.layout = ({ lot }: { lot: InventoryLot }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Batches', href: index() },
        { title: lot.batch_number, href: show(lot.id) },
    ],
});
