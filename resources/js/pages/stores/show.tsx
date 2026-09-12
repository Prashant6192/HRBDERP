import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    PackagePlus,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Trash2,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem, Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
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
import { ALERT_VARIANT, QC_LABEL, QC_VARIANT, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import {
    index as facilitiesIndex,
    show as showFacility,
} from '@/routes/facilities';
import openingStock from '@/routes/facilities/opening-stock';
import { create as createReceipt } from '@/routes/goods-receipts';
import { show as showLot } from '@/routes/lots';
import { activate, deactivate, destroy, update } from '@/routes/stores';
import {
    create as createTransfer,
    show as showTransfer,
} from '@/routes/transfers';
import type { LotQcStatus, StockAlertLevel } from '@/types';

type StoreView = {
    id: number;
    code: string;
    name: string;
    is_active: boolean;
    is_quarantine: boolean;
    notes: string | null;
    sort_order: number;
    type: string;
    badge: string;
    category: string | null;
    category_id: number | null;
    manager: string | null;
    manager_id: number | null;
    facility: { id: number; code: string; name: string } | null;
    locations: {
        id: number;
        code: string;
        name: string;
        type: string;
        is_active: boolean;
    }[];
    has_history: boolean;
};

type Row = {
    item_id: number;
    code: string;
    name: string;
    type: string;
    uom: string | null;
    display_scale: number;
    on_hand: string;
    reserved: string;
    available: string;
    reorder_level: string | null;
    minimum_stock: string | null;
    store_specific: boolean;
    level: StockAlertLevel;
    level_label: string;
    severity: number;
};

type LotRow = {
    id: number;
    batch_number: string;
    item: string | null;
    item_code: string | null;
    qc_status: LotQcStatus;
    expiry_at: string | null;
    on_hand: string;
};
type Movement = {
    id: number;
    number: string | null;
    type: string | null;
    item: string | null;
    batch: string | null;
    quantity: string;
    reason: string | null;
    at: string | null;
};

const TONE: Record<
    string,
    'success' | 'warning' | 'destructive' | 'muted' | 'info'
> = {
    success: 'success',
    warning: 'warning',
    danger: 'destructive',
    neutral: 'muted',
    info: 'info',
};

export default function ShowStore({
    store,
    rows,
    lots,
    movements,
    openTransfers,
    openReceipts,
    can,
}: {
    store: StoreView;
    rows: Row[];
    lots: LotRow[];
    movements: Movement[];
    openTransfers: {
        id: number;
        number: string;
        status_label: string;
        tone: string;
        direction: 'in' | 'out';
    }[];
    openReceipts: number;
    can: {
        update: boolean;
        deactivate: boolean;
        delete: boolean;
        work_here: boolean;
        receive: boolean;
        transfer: boolean;
        opening_stock: boolean;
    };
}) {
    const [editing, setEditing] = useState(false);
    const form = useForm({
        name: store.name,
        code: store.code,
        manager_id: store.manager_id ? String(store.manager_id) : '',
        notes: store.notes ?? '',
        is_active: store.is_active,
    });
    const isRm = store.type === 'raw_material' || store.type === 'packaging';
    const isFg = store.type === 'finished_goods';

    return (
        <>
            <Head title={store.code} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={store.name}
                    description={`${store.code} · ${store.category ?? store.type}${store.facility ? ` · ${store.facility.name}` : ''}`}
                    actions={
                        <>
                            {isRm && can.receive && store.is_active && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={createReceipt({
                                            query: { warehouse: store.id },
                                        })}
                                    >
                                        <PackagePlus className="size-4" />
                                        Receive goods
                                    </Link>
                                </Button>
                            )}
                            {can.transfer && store.is_active && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={createTransfer({
                                            query: { source: store.id },
                                        })}
                                    >
                                        <ArrowLeftRight className="size-4" />
                                        {isFg
                                            ? 'Transfer to warehouse'
                                            : 'Transfer stock'}
                                    </Link>
                                </Button>
                            )}
                            {can.opening_stock &&
                                store.is_active &&
                                store.facility && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={openingStock.create(
                                                store.facility.id,
                                                { query: { store: store.id } },
                                            )}
                                        >
                                            <Plus className="size-4" />
                                            Opening stock
                                        </Link>
                                    </Button>
                                )}
                            {can.update && (
                                <Button
                                    variant={editing ? 'ghost' : 'outline'}
                                    onClick={() => setEditing(!editing)}
                                >
                                    {editing ? (
                                        <X className="size-4" />
                                    ) : (
                                        <Pencil className="size-4" />
                                    )}
                                    {editing ? 'Cancel' : 'Edit'}
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <ActiveBadge active={store.is_active} />
                    <span className="bg-primary/10 text-primary rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold">
                        {store.badge}
                    </span>
                    {store.is_quarantine && (
                        <StatusBadge variant="warning">
                            Held stock — released by QC only
                        </StatusBadge>
                    )}
                    {!can.work_here && (
                        <StatusBadge variant="muted">
                            View only — you are not assigned here
                        </StatusBadge>
                    )}
                    {openReceipts > 0 && (
                        <StatusBadge variant="info">
                            {openReceipts} draft receipt
                            {openReceipts === 1 ? '' : 's'}
                        </StatusBadge>
                    )}
                </div>

                {editing && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(update(store.id).url, {
                                preserveScroll: true,
                                onSuccess: () => setEditing(false),
                            });
                        }}
                        className="bg-card grid gap-3 rounded-xl border p-5 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        <Field
                            label="Store name"
                            htmlFor="store-name"
                            required
                            error={form.errors.name}
                        >
                            <Input
                                id="store-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Code"
                            htmlFor="store-code"
                            required
                            error={form.errors.code}
                            hint={
                                store.has_history
                                    ? 'Careful: labels already printed carry the old code.'
                                    : undefined
                            }
                        >
                            <Input
                                id="store-code"
                                value={form.data.code}
                                onChange={(e) =>
                                    form.setData(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="store-notes"
                            error={form.errors.notes}
                        >
                            <Input
                                id="store-notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                        <div className="flex items-end">
                            <Button type="submit" disabled={form.processing}>
                                Save
                            </Button>
                        </div>
                    </form>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border lg:col-span-2">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">
                                {isFg
                                    ? 'Finished goods on hand'
                                    : 'Stock on hand'}
                            </h2>
                            <p className="text-muted-foreground text-xs">
                                {isRm
                                    ? 'Thresholds marked ★ are set for this store; the rest come from the material master.'
                                    : 'On hand, held for orders or transfers, and free to issue.'}
                            </p>
                        </div>
                        {rows.length === 0 ? (
                            <p className="text-muted-foreground p-5 text-sm">
                                Nothing on hand in this store.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Item</TableHead>
                                            <TableHead className="text-right">
                                                On hand
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Reserved
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Available
                                            </TableHead>
                                            {isRm && (
                                                <TableHead className="text-right">
                                                    Reorder at
                                                </TableHead>
                                            )}
                                            <TableHead>Level</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {rows.map((r) => (
                                            <TableRow key={r.item_id}>
                                                <TableCell>
                                                    <div className="font-medium">
                                                        {r.name}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">
                                                        {r.code}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(
                                                        r.on_hand,
                                                        r.display_scale,
                                                    )}{' '}
                                                    {r.uom}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(
                                                        r.reserved,
                                                        r.display_scale,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(
                                                        r.available,
                                                        r.display_scale,
                                                    )}
                                                </TableCell>
                                                {isRm && (
                                                    <TableCell className="text-right tabular-nums">
                                                        {r.reorder_level
                                                            ? `${qty(r.reorder_level, r.display_scale)}${r.store_specific ? ' ★' : ''}`
                                                            : '—'}
                                                    </TableCell>
                                                )}
                                                <TableCell>
                                                    <StatusBadge
                                                        variant={
                                                            ALERT_VARIANT[
                                                                r.level
                                                            ]
                                                        }
                                                    >
                                                        {r.level_label}
                                                    </StatusBadge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </section>

                    <div className="space-y-6">
                        <section className="bg-card rounded-xl border p-6">
                            <h2 className="mb-4 font-semibold">Details</h2>
                            <dl className="grid gap-4">
                                <DetailItem label="Facility">
                                    {store.facility ? (
                                        <Link
                                            href={showFacility(
                                                store.facility.id,
                                            )}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {store.facility.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </DetailItem>
                                <DetailItem label="Category">
                                    {store.category ?? store.type}
                                </DetailItem>
                                <DetailItem label="Store manager">
                                    {store.manager ?? '—'}
                                </DetailItem>
                                <DetailItem label="Notes">
                                    {store.notes ?? '—'}
                                </DetailItem>
                            </dl>
                            <div className="mt-5 flex flex-wrap gap-2">
                                {can.deactivate &&
                                    (store.is_active ? (
                                        <ConfirmDialog
                                            trigger={
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <PowerOff className="size-4" />
                                                    Deactivate
                                                </Button>
                                            }
                                            title={`Deactivate ${store.code}?`}
                                            description="The store must hold no stock. Its history is kept and it can be reactivated later."
                                            confirmLabel="Deactivate"
                                            destructive
                                            action={() =>
                                                router.post(
                                                    deactivate(store.id).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    activate(store.id).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Power className="size-4" />
                                            Reactivate
                                        </Button>
                                    ))}
                                {can.delete && !store.has_history && (
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="outline" size="sm">
                                                <Trash2 className="size-4" />
                                                Remove
                                            </Button>
                                        }
                                        title={`Remove ${store.code}?`}
                                        description="This store was never used, so it can be removed."
                                        confirmLabel="Remove"
                                        destructive
                                        action={() =>
                                            router.delete(destroy(store.id).url)
                                        }
                                    />
                                )}
                                {can.delete && store.has_history && (
                                    <p className="text-muted-foreground text-xs">
                                        This store cannot be deleted because
                                        operational history exists. Deactivate
                                        it instead.
                                    </p>
                                )}
                            </div>
                        </section>

                        {openTransfers.length > 0 && (
                            <section className="bg-card rounded-xl border p-6">
                                <h2 className="mb-3 font-semibold">
                                    Open transfers
                                </h2>
                                <ul className="space-y-2 text-sm">
                                    {openTransfers.map((t) => (
                                        <li
                                            key={t.id}
                                            className="flex items-center justify-between gap-2"
                                        >
                                            <Link
                                                href={showTransfer(t.id)}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {t.number}
                                            </Link>
                                            <span className="flex items-center gap-1">
                                                <StatusBadge
                                                    variant={
                                                        t.direction === 'in'
                                                            ? 'info'
                                                            : 'muted'
                                                    }
                                                >
                                                    {t.direction === 'in'
                                                        ? 'In'
                                                        : 'Out'}
                                                </StatusBadge>
                                                <StatusBadge
                                                    variant={
                                                        TONE[t.tone] ?? 'muted'
                                                    }
                                                >
                                                    {t.status_label}
                                                </StatusBadge>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="bg-card rounded-xl border p-6">
                            <h2 className="mb-3 font-semibold">Locations</h2>
                            {store.locations.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No zones, racks or bins defined. Stock is
                                    tracked at store level.
                                </p>
                            ) : (
                                <ul className="flex flex-wrap gap-1.5">
                                    {store.locations.map((l) => (
                                        <li
                                            key={l.id}
                                            className="bg-muted rounded px-2 py-1 font-mono text-xs"
                                            title={l.name}
                                        >
                                            {l.code}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>
                    </div>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Batches in this store</h2>
                    </div>
                    {lots.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            No batches on hand.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Batch</TableHead>
                                        <TableHead>Item</TableHead>
                                        <TableHead>QC</TableHead>
                                        <TableHead>Expiry</TableHead>
                                        <TableHead className="text-right">
                                            On hand
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {lots.map((l) => (
                                        <TableRow key={l.id}>
                                            <TableCell>
                                                <Link
                                                    href={showLot(l.id)}
                                                    className="font-mono text-xs font-medium underline-offset-4 hover:underline"
                                                >
                                                    {l.batch_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {l.item}
                                                <span className="text-muted-foreground ml-1 text-xs">
                                                    {l.item_code}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    variant={
                                                        QC_VARIANT[l.qc_status]
                                                    }
                                                >
                                                    {QC_LABEL[l.qc_status]}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell>
                                                {l.expiry_at ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {qty(l.on_hand)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Recent movements</h2>
                        <p className="text-muted-foreground text-xs">
                            The last fifty ledger lines through this store.
                        </p>
                    </div>
                    {movements.length === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            Nothing has moved through this store yet.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>When</TableHead>
                                        <TableHead>Transaction</TableHead>
                                        <TableHead>Item</TableHead>
                                        <TableHead>Batch</TableHead>
                                        <TableHead className="text-right">
                                            Qty
                                        </TableHead>
                                        <TableHead>Reason</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {movements.map((m) => (
                                        <TableRow key={m.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {m.at
                                                    ? new Date(
                                                          m.at,
                                                      ).toLocaleString(
                                                          'en-IN',
                                                          {
                                                              dateStyle:
                                                                  'medium',
                                                              timeStyle:
                                                                  'short',
                                                          },
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <span className="font-mono text-xs">
                                                    {m.number}
                                                </span>
                                                <div className="text-muted-foreground text-xs">
                                                    {m.type}
                                                </div>
                                            </TableCell>
                                            <TableCell>{m.item}</TableCell>
                                            <TableCell className="font-mono text-xs">
                                                {m.batch ?? '—'}
                                            </TableCell>
                                            <TableCell
                                                className={`text-right tabular-nums ${Number(m.quantity) < 0 ? 'text-red-700 dark:text-red-300' : 'text-emerald-700 dark:text-emerald-300'}`}
                                            >
                                                {qty(m.quantity)}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground max-w-xs truncate text-xs">
                                                {m.reason ?? '—'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

ShowStore.layout = ({ store }: { store: StoreView }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facilities & Warehouses', href: facilitiesIndex() },
        ...(store.facility
            ? [
                  {
                      title: store.facility.name,
                      href: showFacility(store.facility.id),
                  },
              ]
            : []),
        { title: store.code, href: '#' },
    ],
});
