import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowRight, Check, PackageCheck, Send, Truck, X } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as showFacility } from '@/routes/facilities';
import { show as showStore } from '@/routes/stores';
import {
    approve,
    cancel,
    dispatch,
    index,
    pack,
    receive,
    reject,
    request,
    show,
    transit,
} from '@/routes/transfers';
import type { StockTransferStatus } from '@/types';
import { TRANSFER_VARIANT } from './index';

type Line = {
    id: number;
    line_no: number;
    item_id: number;
    item_code: string;
    item_name: string;
    item_type: string;
    batch: string | null;
    expiry_at: string | null;
    uom: string;
    requested: string;
    dispatched: string;
    received: string;
    written_off: string;
    outstanding: string;
    available_at_source: string;
    notes: string | null;
};

type Transfer = {
    id: number;
    number: string;
    status: StockTransferStatus;
    status_label: string;
    status_tone: string;
    requires_inspection: boolean;
    expected_at: string | null;
    reason: string | null;
    notes: string | null;
    vehicle_ref: string | null;
    source: {
        facility_id: number;
        facility: string;
        store_id: number;
        store: string;
        store_code: string;
    };
    destination: {
        facility_id: number;
        facility: string;
        store_id: number;
        store: string;
        store_code: string;
    };
    timeline: {
        label: string;
        by: string | null;
        at: string | null;
        done: boolean;
    }[];
    created_at: string | null;
};

const when = (v: string | null) =>
    v
        ? new Date(v).toLocaleString('en-IN', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';

export default function ShowTransfer({
    transfer,
    lines,
    can,
}: {
    transfer: Transfer;
    lines: Line[];
    can: {
        request: boolean;
        approve: boolean;
        reject: boolean;
        pack: boolean;
        dispatch: boolean;
        transit: boolean;
        receive: boolean;
        cancel: boolean;
    };
}) {
    const [receiving, setReceiving] = useState(false);
    const [vehicle, setVehicle] = useState(transfer.vehicle_ref ?? '');
    const receiveForm = useForm<{
        lines: {
            line_id: number;
            quantity: string;
            written_off: string;
            notes: string;
        }[];
    }>({
        lines: lines
            .filter((l) => Number(l.outstanding) > 0)
            .map((l) => ({
                line_id: l.id,
                quantity: l.outstanding,
                written_off: '',
                notes: '',
            })),
    });
    const post = (url: string, data: Record<string, string | undefined> = {}) =>
        router.post(url, data, { preserveScroll: true });
    const onTheRoad = [
        'dispatched',
        'in_transit',
        'partially_received',
    ].includes(transfer.status);

    return (
        <>
            <Head title={transfer.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={transfer.number}
                    description={transfer.reason ?? 'Stock transfer'}
                    actions={
                        <>
                            {can.request && (
                                <Button
                                    onClick={() =>
                                        post(request(transfer.id).url)
                                    }
                                >
                                    <Send className="size-4" />
                                    Send for approval
                                </Button>
                            )}
                            {can.approve && (
                                <Button
                                    onClick={() =>
                                        post(approve(transfer.id).url)
                                    }
                                >
                                    <Check className="size-4" />
                                    Approve &amp; hold stock
                                </Button>
                            )}
                            {can.reject && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline">
                                            <X className="size-4" />
                                            Reject
                                        </Button>
                                    }
                                    title={`Reject ${transfer.number}?`}
                                    description="Nothing is held; the request is closed."
                                    confirmLabel="Reject"
                                    destructive
                                    action={() => post(reject(transfer.id).url)}
                                />
                            )}
                            {can.pack && (
                                <Button
                                    variant="outline"
                                    onClick={() => post(pack(transfer.id).url)}
                                >
                                    <PackageCheck className="size-4" />
                                    Mark packed
                                </Button>
                            )}
                            {can.dispatch && (
                                <ConfirmDialog
                                    trigger={
                                        <Button>
                                            <Truck className="size-4" />
                                            Dispatch
                                        </Button>
                                    }
                                    title={`Dispatch ${transfer.number}?`}
                                    description="The held stock leaves the source store and sits in transit until the destination receives it."
                                    confirmLabel="Dispatch"
                                    action={() =>
                                        post(dispatch(transfer.id).url, {
                                            vehicle_ref: vehicle || undefined,
                                        })
                                    }
                                />
                            )}
                            {can.transit && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        post(transit(transfer.id).url)
                                    }
                                >
                                    Mark in transit
                                </Button>
                            )}
                            {can.receive && (
                                <Button
                                    onClick={() => setReceiving(!receiving)}
                                >
                                    <PackageCheck className="size-4" />
                                    {receiving
                                        ? 'Close receipt form'
                                        : 'Receive'}
                                </Button>
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline">
                                            <X className="size-4" />
                                            Cancel
                                        </Button>
                                    }
                                    title={`Cancel ${transfer.number}?`}
                                    description="Anything held at the source is released. Cancelling is not possible once dispatched."
                                    confirmLabel="Cancel transfer"
                                    destructive
                                    action={() => post(cancel(transfer.id).url)}
                                />
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant={TRANSFER_VARIANT[transfer.status]}>
                        {transfer.status_label}
                    </StatusBadge>
                    {transfer.requires_inspection && (
                        <StatusBadge variant="warning">
                            Inspect on receipt
                        </StatusBadge>
                    )}
                    {transfer.vehicle_ref && (
                        <StatusBadge variant="muted">
                            Vehicle {transfer.vehicle_ref}
                        </StatusBadge>
                    )}
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            <div>
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    From
                                </p>
                                <Link
                                    href={showFacility(
                                        transfer.source.facility_id,
                                    )}
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {transfer.source.facility}
                                </Link>
                                <div>
                                    <Link
                                        href={showStore(
                                            transfer.source.store_id,
                                        )}
                                        className="text-muted-foreground font-mono text-xs underline-offset-4 hover:underline"
                                    >
                                        {transfer.source.store_code} ·{' '}
                                        {transfer.source.store}
                                    </Link>
                                </div>
                            </div>
                            <ArrowRight className="text-muted-foreground size-5" />
                            <div>
                                <p className="text-muted-foreground text-xs tracking-wide uppercase">
                                    To
                                </p>
                                <Link
                                    href={showFacility(
                                        transfer.destination.facility_id,
                                    )}
                                    className="font-medium underline-offset-4 hover:underline"
                                >
                                    {transfer.destination.facility}
                                </Link>
                                <div>
                                    <Link
                                        href={showStore(
                                            transfer.destination.store_id,
                                        )}
                                        className="text-muted-foreground font-mono text-xs underline-offset-4 hover:underline"
                                    >
                                        {transfer.destination.store_code} ·{' '}
                                        {transfer.destination.store}
                                    </Link>
                                </div>
                            </div>
                        </div>
                        <dl className="mt-5 grid gap-4 sm:grid-cols-3">
                            <DetailItem label="Expected">
                                {transfer.expected_at ?? '—'}
                            </DetailItem>
                            <DetailItem label="Raised">
                                {when(transfer.created_at)}
                            </DetailItem>
                            <DetailItem label="Notes">
                                {transfer.notes ?? '—'}
                            </DetailItem>
                        </dl>
                        {can.dispatch && (
                            <div className="mt-5 max-w-xs">
                                <label
                                    className="text-muted-foreground text-xs tracking-wide uppercase"
                                    htmlFor="vehicle"
                                >
                                    Vehicle / LR number
                                </label>
                                <Input
                                    id="vehicle"
                                    value={vehicle}
                                    onChange={(e) => setVehicle(e.target.value)}
                                    placeholder="UK-06-AB-1234"
                                    className="mt-1"
                                />
                            </div>
                        )}
                    </section>
                    <section className="bg-card rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Progress</h2>
                        <ol className="space-y-3">
                            {transfer.timeline.map((step) => (
                                <li
                                    key={step.label}
                                    className="flex items-start gap-3 text-sm"
                                >
                                    <span
                                        className={cn(
                                            'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border',
                                            step.done
                                                ? 'bg-primary border-primary text-primary-foreground'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {step.done && (
                                            <Check className="size-3" />
                                        )}
                                    </span>
                                    <span>
                                        <span
                                            className={
                                                step.done
                                                    ? 'font-medium'
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            {step.label}
                                        </span>
                                        {step.done && (
                                            <span className="text-muted-foreground block text-xs">
                                                {step.by ?? '—'} ·{' '}
                                                {when(step.at)}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>

                {receiving && can.receive && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            receiveForm.post(receive(transfer.id).url, {
                                preserveScroll: true,
                                onSuccess: () => setReceiving(false),
                            });
                        }}
                        className="bg-card rounded-xl border"
                    >
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                Receive at {transfer.destination.facility}
                            </h2>
                            <p className="text-muted-foreground text-xs">
                                Enter what arrived. Anything lost or damaged on
                                the way is written off from transit; whatever is
                                left stays in transit and the transfer remains
                                open.
                            </p>
                        </div>
                        {receiveForm.errors.lines && (
                            <p className="text-destructive px-5 pt-4 text-sm">
                                {receiveForm.errors.lines}
                            </p>
                        )}
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Item</TableHead>
                                    <TableHead>Batch</TableHead>
                                    <TableHead className="text-right">
                                        In transit
                                    </TableHead>
                                    <TableHead className="w-36">
                                        Received
                                    </TableHead>
                                    <TableHead className="w-36">
                                        Written off
                                    </TableHead>
                                    <TableHead>Notes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {receiveForm.data.lines.map((row, i) => {
                                    const line = lines.find(
                                        (l) => l.id === row.line_id,
                                    )!;
                                    const set = (patch: Partial<typeof row>) =>
                                        receiveForm.setData(
                                            'lines',
                                            receiveForm.data.lines.map(
                                                (r, j) =>
                                                    j === i
                                                        ? { ...r, ...patch }
                                                        : r,
                                            ),
                                        );
                                    return (
                                        <TableRow key={row.line_id}>
                                            <TableCell>
                                                {line.item_name}
                                                <div className="text-muted-foreground text-xs">
                                                    {line.item_code}
                                                </div>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {line.batch ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {qty(line.outstanding)}{' '}
                                                {line.uom}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    value={row.quantity}
                                                    onChange={(e) =>
                                                        set({
                                                            quantity:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    value={row.written_off}
                                                    onChange={(e) =>
                                                        set({
                                                            written_off:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    value={row.notes}
                                                    onChange={(e) =>
                                                        set({
                                                            notes: e.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder="Discrepancy note"
                                                />
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        <div className="flex gap-3 p-5">
                            <Button
                                type="submit"
                                disabled={receiveForm.processing}
                            >
                                Book receipt
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setReceiving(false)}
                            >
                                Cancel
                            </Button>
                        </div>
                    </form>
                )}

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Lines</h2>
                        <p className="text-muted-foreground text-xs">
                            One line per batch once approved, so the document
                            says exactly which batches travelled.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-10">#</TableHead>
                                    <TableHead>Item</TableHead>
                                    <TableHead>Batch</TableHead>
                                    <TableHead className="text-right">
                                        Requested
                                    </TableHead>
                                    {!onTheRoad &&
                                        transfer.status !== 'received' &&
                                        transfer.status !== 'discrepancy' && (
                                            <TableHead className="text-right">
                                                Free at source
                                            </TableHead>
                                        )}
                                    <TableHead className="text-right">
                                        Dispatched
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Received
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Written off
                                    </TableHead>
                                    <TableHead>Notes</TableHead>
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
                                                {l.item_name}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {l.item_code}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {l.batch ?? 'by expiry'}
                                            {l.expiry_at && (
                                                <div className="text-muted-foreground">
                                                    exp {l.expiry_at}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(l.requested)} {l.uom}
                                        </TableCell>
                                        {!onTheRoad &&
                                            transfer.status !== 'received' &&
                                            transfer.status !==
                                                'discrepancy' && (
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(l.available_at_source)}
                                                </TableCell>
                                            )}
                                        <TableCell className="text-right tabular-nums">
                                            {Number(l.dispatched) > 0
                                                ? qty(l.dispatched)
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(l.received) > 0
                                                ? qty(l.received)
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {Number(l.written_off) > 0 ? (
                                                <span className="text-red-700 dark:text-red-300">
                                                    {qty(l.written_off)}
                                                </span>
                                            ) : (
                                                '—'
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {l.notes ?? '—'}
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

ShowTransfer.layout = ({ transfer }: { transfer: Transfer }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stock Transfers', href: index() },
        { title: transfer.number, href: show(transfer.id) },
    ],
});
