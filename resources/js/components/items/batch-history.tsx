import { Link } from '@inertiajs/react';
import { Boxes, FileText } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { show as showGoodsReceipt } from '@/routes/goods-receipts';
import { show as showLot } from '@/routes/lots';

export type BatchRow = {
    id: number;
    batch_number: string;
    supplier_batch_ref: string | null;
    brand: string | null;
    brand_kind: 'client' | 'vendor' | null;
    qc_status: string;
    qc_status_label: string;
    qc_variant: 'success' | 'warning' | 'destructive' | 'muted' | 'info';
    qc_decided_at: string | null;
    qc_decided_by: string | null;
    manufactured_at: string | null;
    expiry_at: string | null;
    received_at: string | null;
    received_quantity: string;
    on_hand: string;
    unit_cost: string | null;
    value: string | null;
    stores: {
        code: string | null;
        name: string | null;
        quarantine: boolean;
        quantity: string;
    }[];
    source: 'receipt' | 'opening' | 'production';
    receipt: { id: number; number: string; invoice_ref: string | null } | null;
    expired: boolean;
};

export type BatchHistory = {
    rows: BatchRow[];
    total: number;
    received_value: string;
};

const SOURCE_LABEL: Record<BatchRow['source'], string> = {
    receipt: 'Delivery',
    opening: 'Opening stock',
    production: 'Made here',
};

const date = (value: string | null): string =>
    value === null
        ? '—'
        : new Date(value).toLocaleDateString('en-IN', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          });

const rupees = (value: string | null): string =>
    value === null
        ? '—'
        : `₹${Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/**
 * Every batch of this material ever booked in: where it came from, what QC
 * said, when it was made and when it dies, what it cost, and how much of it
 * is left. The whole life of the ingredient on one screen.
 */
export function BatchHistory({
    history,
    unit,
    noun = 'ingredient',
}: {
    history: BatchHistory;
    unit?: string | null;
    noun?: string;
}) {
    if (history.rows.length === 0) {
        return (
            <section className="bg-card rounded-xl border p-6">
                <h2 className="flex items-center gap-2 font-semibold">
                    <Boxes className="text-muted-foreground size-4" />
                    Inventory history
                </h2>
                <p className="text-muted-foreground mt-2 text-sm">
                    No batch of this {noun} has been booked in yet. It appears
                    here the moment a delivery is received or opening stock is
                    entered.
                </p>
            </section>
        );
    }

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-end justify-between gap-3 border-b px-6 py-4">
                <div>
                    <h2 className="flex items-center gap-2 font-semibold">
                        <Boxes className="text-muted-foreground size-4" />
                        Inventory history
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        Every batch booked in, newest first. Quantities are in{' '}
                        {unit ?? 'the stock unit'}.
                    </p>
                </div>
                <div className="text-right">
                    <p className="text-muted-foreground text-xs tracking-wide uppercase">
                        {history.total} batch{history.total === 1 ? '' : 'es'} ·
                        value received
                    </p>
                    <p className="text-lg font-semibold tabular-nums">
                        {rupees(history.received_value)}
                    </p>
                </div>
            </div>

            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="min-w-40">Batch</TableHead>
                            <TableHead className="min-w-36">
                                Brand / supplier
                            </TableHead>
                            <TableHead className="min-w-28">
                                QC status
                            </TableHead>
                            <TableHead className="min-w-28">Mfg date</TableHead>
                            <TableHead className="min-w-28">Expiry</TableHead>
                            <TableHead className="min-w-28 text-right">
                                Received
                            </TableHead>
                            <TableHead className="min-w-28 text-right">
                                On hand
                            </TableHead>
                            <TableHead className="min-w-24 text-right">
                                Rate
                            </TableHead>
                            <TableHead className="min-w-40">
                                Where it is
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {history.rows.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>
                                    <Link
                                        href={showLot(row.id)}
                                        className="font-mono text-sm font-medium underline-offset-4 hover:underline"
                                    >
                                        {row.batch_number}
                                    </Link>
                                    <span className="text-muted-foreground block text-xs">
                                        {SOURCE_LABEL[row.source]} ·{' '}
                                        {date(row.received_at)}
                                    </span>
                                    {row.supplier_batch_ref && (
                                        <span className="text-muted-foreground block text-xs">
                                            Supplier batch{' '}
                                            {row.supplier_batch_ref}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <span className="text-sm">
                                        {row.brand ?? '—'}
                                    </span>
                                    {row.brand_kind === 'client' && (
                                        <span className="text-muted-foreground block text-xs">
                                            Client&rsquo;s own material
                                        </span>
                                    )}
                                    {row.receipt && (
                                        <Link
                                            href={showGoodsReceipt(
                                                row.receipt.id,
                                            )}
                                            className="text-muted-foreground mt-0.5 flex items-center gap-1 text-xs underline-offset-4 hover:underline"
                                        >
                                            <FileText className="size-3" />
                                            {row.receipt.invoice_ref ??
                                                row.receipt.number}
                                        </Link>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <StatusBadge variant={row.qc_variant}>
                                        {row.qc_status_label}
                                    </StatusBadge>
                                    {row.qc_decided_by && (
                                        <span className="text-muted-foreground block text-xs">
                                            by {row.qc_decided_by}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-sm">
                                    {date(row.manufactured_at)}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'text-sm',
                                        row.expired &&
                                            'font-medium text-red-700 dark:text-red-300',
                                    )}
                                >
                                    {date(row.expiry_at)}
                                    {row.expired && (
                                        <span className="block text-xs">
                                            expired
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    {row.received_quantity}
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'text-right text-sm font-medium tabular-nums',
                                        Number(row.on_hand) === 0 &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {row.on_hand}
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    {rupees(row.unit_cost)}
                                </TableCell>
                                <TableCell>
                                    {row.stores.length === 0 ? (
                                        <span className="text-muted-foreground text-xs">
                                            Fully used
                                        </span>
                                    ) : (
                                        <ul className="space-y-0.5 text-xs">
                                            {row.stores.map((store, i) => (
                                                <li key={i}>
                                                    <span className="font-medium">
                                                        {store.quantity}
                                                    </span>{' '}
                                                    <span
                                                        className={cn(
                                                            store.quarantine &&
                                                                'text-amber-700 dark:text-amber-300',
                                                        )}
                                                    >
                                                        {store.name ??
                                                            store.code}
                                                        {store.quarantine
                                                            ? ' (quarantine)'
                                                            : ''}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}
