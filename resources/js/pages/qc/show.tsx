import { Head, Link, useForm } from '@inertiajs/react';
import { CheckCircle2, PauseCircle, Printer, XCircle } from 'lucide-react';
import { useState } from 'react';
import { DetailItem } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { date, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { show as showReceipt } from '@/routes/goods-receipts';
import { show as showLot, sticker } from '@/routes/lots';
import { approve, hold, index, reject, show } from '@/routes/qc';
import type { QcInspection, SelectOption } from '@/types';

type Decision = 'approve' | 'reject' | 'hold';

export default function ShowQcInspection({
    inspection,
    stockLocations,
    destinations,
    can,
}: {
    inspection: QcInspection;
    stockLocations: {
        warehouse: string | null;
        is_quarantine: boolean | null;
        on_hand: string;
    }[];
    destinations: SelectOption[];
    can: { approve: boolean; reject: boolean; hold: boolean; sticker: boolean };
}) {
    const [decision, setDecision] = useState<Decision | null>(null);

    const form = useForm({
        remarks: '',
        destination_warehouse_id: String(
            inspection.destination_warehouse?.id ?? '',
        ),
    });

    const submit = (kind: Decision) => {
        setDecision(kind);
        const url = { approve, reject, hold }[kind](inspection.id).url;
        form.post(url, {
            preserveScroll: true,
            onFinish: () => setDecision(null),
        });
    };

    const lot = inspection.lot;
    const open =
        inspection.status === 'pending' || inspection.status === 'on_hold';

    return (
        <>
            <Head title={inspection.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`${inspection.number} · ${lot?.batch_number ?? ''}`}
                    description={`${inspection.item?.code} — ${inspection.item?.name}`}
                    actions={
                        can.sticker && (
                            <Button asChild>
                                <a
                                    href={sticker(inspection.lot_id).url}
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
                            <DetailItem label="Batch number">
                                <Link
                                    href={showLot(inspection.lot_id)}
                                    className="font-mono font-medium hover:underline"
                                >
                                    {lot?.batch_number}
                                </Link>
                            </DetailItem>
                            <DetailItem label="Quantity">
                                {qty(inspection.quantity)}{' '}
                                {inspection.item?.stock_uom?.code}
                            </DetailItem>
                            <DetailItem label="Supplier batch">
                                {lot?.supplier_batch_ref ?? '—'}
                            </DetailItem>
                            <DetailItem label="Vendor">
                                {lot?.vendor?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Received">
                                {date(lot?.received_at)}
                            </DetailItem>
                            <DetailItem label="Manufactured">
                                {date(lot?.manufactured_at)}
                            </DetailItem>
                            <DetailItem label="Expiry">
                                {date(lot?.expiry_at)}
                            </DetailItem>
                            <DetailItem label="Goods receipt">
                                {inspection.receipt_line?.receipt ? (
                                    <Link
                                        href={showReceipt(
                                            inspection.receipt_line.receipt.id,
                                        )}
                                        className="hover:underline"
                                    >
                                        {inspection.receipt_line.receipt.number}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </DetailItem>
                            <DetailItem label="Currently in">
                                {stockLocations.length === 0
                                    ? '—'
                                    : stockLocations
                                          .map(
                                              (l) =>
                                                  `${l.warehouse}${l.is_quarantine ? ' (quarantine)' : ''}: ${qty(l.on_hand)}`,
                                          )
                                          .join(', ')}
                            </DetailItem>
                            <DetailItem label="Release to">
                                {inspection.destination_warehouse
                                    ? `${inspection.destination_warehouse.code} — ${inspection.destination_warehouse.name}`
                                    : '—'}
                            </DetailItem>
                        </dl>
                    </section>

                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Decision</h2>
                        <StatusBadge variant={QC_VARIANT[inspection.status]}>
                            {QC_LABEL[inspection.status]}
                        </StatusBadge>
                        {inspection.decided_at && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                {new Date(
                                    inspection.decided_at,
                                ).toLocaleString()}
                                {inspection.decided_by
                                    ? ` by ${inspection.decided_by.name}`
                                    : ''}
                            </p>
                        )}
                        {inspection.remarks && (
                            <p className="mt-3 text-sm whitespace-pre-line">
                                {inspection.remarks}
                            </p>
                        )}
                    </section>
                </div>

                {open && (can.approve || can.reject || can.hold) && (
                    <section className="bg-card rounded-xl border p-6">
                        <h2 className="font-semibold">Record the decision</h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Approving moves the whole batch from quarantine to
                            the store and makes the sticker printable. Rejecting
                            keeps it in quarantine, marked as rejected, until it
                            is returned.
                        </p>

                        <div className="mt-5 grid gap-5 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="remarks">Remarks</Label>
                                <textarea
                                    id="remarks"
                                    rows={3}
                                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                                    value={form.data.remarks}
                                    onChange={(e) =>
                                        form.setData('remarks', e.target.value)
                                    }
                                    placeholder="Test results, observations, reason for rejection…"
                                />
                                <InputError message={form.errors.remarks} />
                            </div>

                            {can.approve && (
                                <div className="space-y-2">
                                    <Label htmlFor="destination">
                                        Release to
                                    </Label>
                                    <Select
                                        value={
                                            form.data.destination_warehouse_id
                                        }
                                        onValueChange={(v) =>
                                            form.setData(
                                                'destination_warehouse_id',
                                                v,
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="destination"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {destinations.map((d) => (
                                                <SelectItem
                                                    key={d.value}
                                                    value={String(d.value)}
                                                >
                                                    {d.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={
                                            form.errors.destination_warehouse_id
                                        }
                                    />
                                </div>
                            )}
                        </div>

                        <InputError
                            message={
                                (
                                    form.errors as Record<
                                        string,
                                        string | undefined
                                    >
                                ).decision
                            }
                            className="mt-3"
                        />

                        <div className="mt-5 flex flex-wrap gap-3">
                            {can.approve && (
                                <Button
                                    onClick={() => submit('approve')}
                                    disabled={form.processing}
                                >
                                    <CheckCircle2 className="size-4" />
                                    {decision === 'approve' && form.processing
                                        ? 'Approving…'
                                        : 'Approve & release'}
                                </Button>
                            )}
                            {can.reject && (
                                <Button
                                    variant="destructive"
                                    onClick={() => submit('reject')}
                                    disabled={form.processing}
                                >
                                    <XCircle className="size-4" />
                                    Reject
                                </Button>
                            )}
                            {can.hold && (
                                <Button
                                    variant="outline"
                                    onClick={() => submit('hold')}
                                    disabled={form.processing}
                                >
                                    <PauseCircle className="size-4" />
                                    Put on hold
                                </Button>
                            )}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

ShowQcInspection.layout = ({ inspection }: { inspection: QcInspection }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Quality control', href: index() },
        { title: inspection.number, href: show(inspection.id) },
    ],
});
