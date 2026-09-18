import { Head, Link, usePage } from '@inertiajs/react';
import { Radar } from 'lucide-react';
import { StatTile } from '@/components/intelligence/facility-filter';
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
import { QC_LABEL, QC_VARIANT, date, qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, show, trace as traceRoute } from '@/routes/lots';
import { show as showOrder } from '@/routes/manufacturing';
import { show as showTransfer } from '@/routes/transfers';
import type { InventoryLot, LotQcStatus, SharedData } from '@/types';

type Affected = {
    lot: { id: number; batch_number: string };
    item: {
        id: number;
        code: string | null;
        name: string | null;
        type: string | null;
        unit: string | null;
    };
    depth: number;
    via: string | null;
    qc_status: LotQcStatus;
    client: string | null;
    vendor: string | null;
    on_hand: string;
    where: { facility: string; warehouse: string; on_hand: string }[];
    transfers: {
        id: number;
        number: string;
        status: string;
        destination: string;
        quantity: string;
    }[];
    dispatched: { quantity: string; last_at: string | null } | null;
};

type Trace = {
    origin: Affected;
    backward: {
        lot_id: number;
        batch_number: string;
        supplier_batch_ref: string | null;
        code: string;
        name: string;
        vendor: string | null;
        quantity: string;
        order: string;
    }[];
    orders: {
        id: number;
        number: string;
        product: string | null;
        client: string | null;
        status: string;
        completed_at: string | null;
        consumed: string;
        from_batch: string;
        output_batch: string | null;
    }[];
    affected: Affected[];
    summary: {
        batches: number;
        orders: number;
        finished_goods: number;
        facilities: string[];
        clients: string[];
        dispatched: number;
        in_stock: number;
    };
};

export default function LotTrace({
    lot,
    trace,
}: {
    lot: InventoryLot;
    trace: Trace;
}) {
    const brand = usePage<SharedData>().props.erp.brand;
    const s = trace.summary;

    return (
        <>
            <Head title={`Trace ${lot.batch_number}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Recall trace · ${lot.batch_number}`}
                    description={`${lot.item?.name ?? ''}: every batch this lot went into, what those batches produced, where each affected batch sits now, where it was transferred, and which client owns it — read from the ledger. Backwards: what this batch was made from and who supplied it.`}
                    actions={
                        <Button variant="outline" size="sm" asChild>
                            <Link href={show(lot.id)}>Back to the batch</Link>
                        </Button>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
                    <StatTile
                        label="Batches affected"
                        value={s.batches}
                        tone={s.batches > 0 ? 'danger' : 'success'}
                    />
                    <StatTile label="Production batches" value={s.orders} />
                    <StatTile label="Finished goods" value={s.finished_goods} />
                    <StatTile
                        label="Still in stock"
                        value={s.in_stock}
                        hint="can be quarantined now"
                    />
                    <StatTile
                        label="Already dispatched"
                        value={s.dispatched}
                        tone={s.dispatched > 0 ? 'danger' : 'default'}
                        hint="customers to contact"
                    />
                    <StatTile
                        label="Facilities · clients"
                        value={`${s.facilities.length} · ${s.clients.length}`}
                        hint={
                            [...s.facilities, ...s.clients].join(', ') || 'none'
                        }
                    />
                </div>

                <section className="bg-card rounded-2xl border p-5">
                    <h2 className="inline-flex items-center gap-2 font-semibold">
                        <Radar className="text-primary size-4" />
                        This lot now
                    </h2>
                    <p className="text-muted-foreground mt-1 text-sm">
                        {trace.origin.on_hand !== '0'
                            ? `${qty(trace.origin.on_hand)} ${trace.origin.item.unit ?? ''} still on hand: ${trace.origin.where.map((w) => `${w.warehouse} (${qty(w.on_hand)})`).join(', ')}.`
                            : 'Nothing of it is left in any store.'}
                        {trace.origin.vendor
                            ? ` Supplied by ${trace.origin.vendor}.`
                            : ''}
                        {trace.origin.client
                            ? ` Owned by ${trace.origin.client}.`
                            : ''}
                    </p>
                </section>

                {trace.backward.length > 0 && (
                    <section className="bg-card rounded-2xl border">
                        <div className="border-b px-5 py-3">
                            <h2 className="font-semibold">Made from</h2>
                            <p className="text-muted-foreground text-xs">
                                The lots consumed into this batch and who
                                supplied them.
                            </p>
                        </div>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Batch</TableHead>
                                    <TableHead>Material</TableHead>
                                    <TableHead>Supplier</TableHead>
                                    <TableHead>Supplier ref</TableHead>
                                    <TableHead className="text-right">
                                        Consumed
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {trace.backward.map((b) => (
                                    <TableRow key={b.lot_id}>
                                        <TableCell>
                                            <Link
                                                href={traceRoute(b.lot_id)}
                                                className="font-mono font-medium underline-offset-4 hover:underline"
                                            >
                                                {b.batch_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{b.name}</TableCell>
                                        <TableCell>{b.vendor ?? '—'}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {b.supplier_batch_ref ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(b.quantity)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}

                <section className="bg-card rounded-2xl border">
                    <div className="border-b px-5 py-3">
                        <h2 className="font-semibold">Went into</h2>
                        <p className="text-muted-foreground text-xs">
                            Production batches that consumed this lot, or a
                            batch made from it.
                        </p>
                    </div>
                    {trace.orders.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-8 text-center text-sm">
                            Nothing has been made from this lot.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Order</TableHead>
                                    <TableHead>Product</TableHead>
                                    <TableHead>Client</TableHead>
                                    <TableHead>Consumed from</TableHead>
                                    <TableHead className="text-right">
                                        Quantity
                                    </TableHead>
                                    <TableHead>Output batch</TableHead>
                                    <TableHead>Completed</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {trace.orders.map((o) => (
                                    <TableRow key={o.id}>
                                        <TableCell>
                                            <Link
                                                href={showOrder(o.id)}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {o.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {o.product ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {o.client ?? brand}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {o.from_batch}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(o.consumed)}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {o.output_batch ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            {date(o.completed_at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>

                <section className="bg-card rounded-2xl border">
                    <div className="border-b px-5 py-3">
                        <h2 className="font-semibold">Affected batches</h2>
                        <p className="text-muted-foreground text-xs">
                            Where each sits now, where it was transferred, and
                            what has already left.
                        </p>
                    </div>
                    {trace.affected.length === 0 ? (
                        <p className="text-muted-foreground px-5 py-8 text-center text-sm">
                            No downstream batch is affected.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Batch</TableHead>
                                    <TableHead>Item</TableHead>
                                    <TableHead>Via</TableHead>
                                    <TableHead>QC</TableHead>
                                    <TableHead>Client</TableHead>
                                    <TableHead>Where now</TableHead>
                                    <TableHead>Transfers</TableHead>
                                    <TableHead className="text-right">
                                        Dispatched
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {trace.affected.map((a) => (
                                    <TableRow
                                        key={a.lot.id}
                                        className={cn(
                                            a.dispatched && 'bg-red-500/5',
                                        )}
                                    >
                                        <TableCell>
                                            <Link
                                                href={traceRoute(a.lot.id)}
                                                className="font-mono font-medium underline-offset-4 hover:underline"
                                            >
                                                {a.lot.batch_number}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">
                                                level {a.depth}
                                            </div>
                                        </TableCell>
                                        <TableCell>{a.item.name}</TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {a.via ?? '—'}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                variant={
                                                    QC_VARIANT[a.qc_status]
                                                }
                                            >
                                                {QC_LABEL[a.qc_status]}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell>
                                            {a.client ?? brand}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {a.where.length === 0
                                                ? 'none left'
                                                : a.where
                                                      .map(
                                                          (w) =>
                                                              `${w.facility} · ${w.warehouse}: ${qty(w.on_hand)} ${a.item.unit ?? ''}`,
                                                      )
                                                      .join('; ')}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {a.transfers.length === 0
                                                ? '—'
                                                : a.transfers.map((t) => (
                                                      <Link
                                                          key={t.id}
                                                          href={showTransfer(
                                                              t.id,
                                                          )}
                                                          className="block underline-offset-4 hover:underline"
                                                      >
                                                          {t.number} →{' '}
                                                          {t.destination} (
                                                          {qty(t.quantity)})
                                                      </Link>
                                                  ))}
                                        </TableCell>
                                        <TableCell className="text-right text-xs tabular-nums">
                                            {a.dispatched
                                                ? `${qty(a.dispatched.quantity)} · ${date(a.dispatched.last_at)}`
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </section>
            </div>
        </>
    );
}

LotTrace.layout = ({ lot }: { lot: InventoryLot }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Batches', href: index() },
        { title: lot.batch_number, href: show(lot.id) },
        { title: 'Recall trace', href: traceRoute(lot.id) },
    ],
});
