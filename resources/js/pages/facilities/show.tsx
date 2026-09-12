import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Building2,
    CalendarPlus,
    ClipboardList,
    Pencil,
    Plus,
    Power,
    PowerOff,
    Star,
    UserPlus,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    CapabilityBadges,
    StoreBadges,
    money,
} from '@/components/facilities/badges';
import { DetailItem, Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ALERT_VARIANT, qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import employeeAssignments from '@/routes/employee-assignments';
import {
    activate,
    deactivate,
    edit,
    index,
    openingStockSwitch,
    show,
} from '@/routes/facilities';
import facilityEmployees from '@/routes/facilities/employees';
import openingStock from '@/routes/facilities/opening-stock';
import facilityStores from '@/routes/facilities/stores';
import { show as showOrder } from '@/routes/manufacturing';
import { create as createPlan, show as showPlan } from '@/routes/plans';
import {
    show as showStore,
    deactivate as deactivateStore,
    activate as activateStore,
} from '@/routes/stores';
import {
    create as createTransfer,
    show as showTransfer,
} from '@/routes/transfers';
import { show as showUser } from '@/routes/users';
import { show as showReceipt } from '@/routes/goods-receipts';
import type {
    EmployeeAssignmentRow,
    SelectOption,
    StockAlertLevel,
    StoreCategoryOption,
    StoreRow,
} from '@/types';

type FacilityView = {
    id: number;
    code: string;
    name: string;
    type: string | null;
    manager: string | null;
    manager_id: number | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    pincode: string | null;
    country: string | null;
    phone: string | null;
    email: string | null;
    gstin: string | null;
    notes: string | null;
    opening_stock_enabled: boolean;
    is_active: boolean;
    can_manufacture: boolean;
    capabilities: {
        key: string;
        label: string;
        badge: string;
        enabled: boolean;
    }[];
    created_at: string | null;
};

type InventoryRow = {
    store_id: number;
    store: string;
    store_badge: string;
    item_id: number;
    code: string;
    name: string;
    type: string;
    uom: string | null;
    display_scale: number;
    on_hand: string;
    reserved: string;
    available: string;
    level: StockAlertLevel;
    level_label: string;
    severity: number;
};

type TransferRow = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    tone: string;
    direction: 'in' | 'out';
    from: string;
    to: string;
    lines_count: number;
    expected_at: string | null;
    created_at: string | null;
};

type ReceiptRow = {
    id: number;
    number: string;
    vendor: string | null;
    store: string | null;
    status: string;
    status_label: string;
    received_at: string | null;
};

type ActivityRow = {
    id: number;
    actor: string;
    action: string;
    subject: string | null;
    description: string | null;
    created_at: string | null;
};

const TAB_LABELS: Record<string, string> = {
    overview: 'Overview',
    stores: 'Stores',
    inventory: 'Inventory',
    employees: 'Employees',
    transfers: 'Stock Transfers',
    incoming: 'Incoming',
    dispatch: 'Dispatch',
    production: 'Production',
    activity: 'Activity',
    settings: 'Settings',
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

function when(value: string | null | undefined): string {
    return value
        ? new Date(value).toLocaleString('en-IN', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}

export default function ShowFacility({
    facility,
    tab,
    tabs,
    summary,
    stores,
    inventory,
    employees,
    transfers,
    incomingReceipts,
    production,
    activity,
    options,
    can,
}: {
    facility: FacilityView;
    tab: string;
    tabs: string[];
    summary: {
        stores: number;
        active_stores: number;
        employees: number;
        stock_value: string;
        items_in_stock: number;
        open_transfers: number;
        open_orders: number;
    };
    stores: StoreRow[];
    inventory: { rows: InventoryRow[] } | null;
    employees: EmployeeAssignmentRow[] | null;
    transfers: TransferRow[] | null;
    incomingReceipts: ReceiptRow[] | null;
    production: {
        plans: {
            id: number;
            number: string;
            formula: string | null;
            quantity: string;
            uom: string | null;
            status: string;
            status_label: string;
            planned_start_date: string | null;
        }[];
        orders: {
            id: number;
            number: string;
            product: string | null;
            quantity: string;
            uom: string | null;
            status: string;
            status_label: string;
            started_at: string | null;
        }[];
    } | null;
    activity: ActivityRow[] | null;
    options: {
        categories: StoreCategoryOption[];
        employees: SelectOption[];
        managers: SelectOption[];
    } | null;
    can: {
        update: boolean;
        deactivate: boolean;
        add_store: boolean;
        assign: boolean;
        opening_stock: boolean;
        transfer: boolean;
        plan: boolean;
        view_stock: boolean;
    };
}) {
    const tabHref = (t: string) => show(facility.id, { query: { tab: t } }).url;
    const address = [
        facility.address_line_1,
        facility.address_line_2,
        facility.city,
        facility.state,
        facility.pincode,
        facility.country,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <Head title={facility.code} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={facility.name}
                    description={`${facility.code} · ${facility.type ?? 'Facility'}${facility.city ? ` · ${facility.city}` : ''}`}
                    actions={
                        <>
                            {can.transfer && facility.is_active && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={createTransfer({
                                            query: {
                                                from_facility: facility.id,
                                            },
                                        })}
                                    >
                                        <ArrowLeftRight className="size-4" />
                                        Transfer stock
                                    </Link>
                                </Button>
                            )}
                            {can.plan && facility.is_active && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={createPlan({
                                            query: { facility: facility.id },
                                        })}
                                    >
                                        <CalendarPlus className="size-4" />
                                        Plan a batch
                                    </Link>
                                </Button>
                            )}
                            {can.update && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(facility.id)}>
                                        <Pencil className="size-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <ActiveBadge active={facility.is_active} />
                    <CapabilityBadges
                        capabilities={facility.capabilities.filter(
                            (c) => c.enabled,
                        )}
                    />
                    {facility.opening_stock_enabled && (
                        <StatusBadge variant="muted">
                            Opening stock open
                        </StatusBadge>
                    )}
                </div>

                <nav className="-mb-2 flex gap-1 overflow-x-auto border-b">
                    {tabs.map((t) => (
                        <Link
                            key={t}
                            href={tabHref(t)}
                            preserveScroll
                            className={cn(
                                'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap',
                                t === tab
                                    ? 'border-primary text-foreground'
                                    : 'text-muted-foreground hover:text-foreground border-transparent',
                            )}
                        >
                            {TAB_LABELS[t] ?? t}
                        </Link>
                    ))}
                </nav>

                {tab === 'overview' && (
                    <>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                            {[
                                [
                                    'Stores',
                                    `${summary.active_stores}/${summary.stores}`,
                                    'active',
                                ],
                                [
                                    'Employees',
                                    String(summary.employees),
                                    'assigned',
                                ],
                                [
                                    'Stock value',
                                    money(summary.stock_value),
                                    'on hand',
                                ],
                                [
                                    'Items in stock',
                                    String(summary.items_in_stock),
                                    'distinct',
                                ],
                                [
                                    'Open transfers',
                                    String(summary.open_transfers),
                                    facility.can_manufacture
                                        ? `${summary.open_orders} open orders`
                                        : 'in or out',
                                ],
                            ].map(([label, value, hint]) => (
                                <div
                                    key={label}
                                    className="bg-card rounded-xl border p-5"
                                >
                                    <p className="text-muted-foreground text-sm">
                                        {label}
                                    </p>
                                    <p className="mt-2 text-2xl font-semibold tabular-nums">
                                        {value}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {hint}
                                    </p>
                                </div>
                            ))}
                        </div>

                        <div className="grid gap-6 lg:grid-cols-3">
                            <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                                <h2 className="mb-5 font-semibold">Details</h2>
                                <dl className="grid gap-5 sm:grid-cols-2">
                                    <DetailItem label="Facility type">
                                        {facility.type ?? '—'}
                                    </DetailItem>
                                    <DetailItem label="Facility manager">
                                        {facility.manager ?? '—'}
                                    </DetailItem>
                                    <DetailItem label="Address">
                                        {address || '—'}
                                    </DetailItem>
                                    <DetailItem label="Contact">
                                        {[facility.phone, facility.email]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </DetailItem>
                                    <DetailItem label="GSTIN">
                                        {facility.gstin ?? '—'}
                                    </DetailItem>
                                    <DetailItem label="Notes">
                                        {facility.notes ?? '—'}
                                    </DetailItem>
                                </dl>
                            </section>
                            <section className="bg-card h-fit rounded-xl border p-6">
                                <h2 className="mb-4 font-semibold">
                                    Capabilities
                                </h2>
                                <ul className="space-y-2 text-sm">
                                    {facility.capabilities.map((c) => (
                                        <li
                                            key={c.key}
                                            className="flex items-center justify-between"
                                        >
                                            <span
                                                className={
                                                    c.enabled
                                                        ? ''
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {c.label}
                                            </span>
                                            <StatusBadge
                                                variant={
                                                    c.enabled
                                                        ? 'success'
                                                        : 'muted'
                                                }
                                            >
                                                {c.enabled ? 'On' : 'Off'}
                                            </StatusBadge>
                                        </li>
                                    ))}
                                </ul>
                                <div className="mt-5">
                                    <p className="text-muted-foreground mb-2 text-xs tracking-wide uppercase">
                                        Stores
                                    </p>
                                    <StoreBadges stores={stores} max={12} />
                                </div>
                            </section>
                        </div>
                    </>
                )}

                {tab === 'stores' && (
                    <StoresTab
                        facilityId={facility.id}
                        stores={stores}
                        options={options}
                        can={can}
                    />
                )}

                {tab === 'inventory' && (
                    <section className="bg-card rounded-xl border">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">
                                    Inventory by store
                                </h2>
                                <p className="text-muted-foreground text-xs">
                                    Every item held at this facility, worst
                                    alert first. Click a store for its own
                                    screen.
                                </p>
                            </div>
                            {can.opening_stock && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={openingStock.create(facility.id)}
                                    >
                                        <Plus className="size-4" />
                                        Book opening stock
                                    </Link>
                                </Button>
                            )}
                        </div>
                        {!inventory || inventory.rows.length === 0 ? (
                            <p className="text-muted-foreground p-5 text-sm">
                                Nothing on hand at this facility yet.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Store</TableHead>
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
                                            <TableHead>Level</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {inventory.rows.map((r) => (
                                            <TableRow
                                                key={`${r.store_id}-${r.item_id}`}
                                            >
                                                <TableCell>
                                                    <Link
                                                        href={showStore(
                                                            r.store_id,
                                                        )}
                                                        className="font-mono text-xs underline-offset-4 hover:underline"
                                                    >
                                                        {r.store_badge} ·{' '}
                                                        {r.store}
                                                    </Link>
                                                </TableCell>
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
                )}

                {tab === 'employees' && (
                    <EmployeesTab
                        facilityId={facility.id}
                        stores={stores}
                        employees={employees ?? []}
                        options={options}
                        can={can}
                    />
                )}

                {(tab === 'transfers' ||
                    tab === 'incoming' ||
                    tab === 'dispatch') && (
                    <>
                        <section className="bg-card rounded-xl border">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                                <div>
                                    <h2 className="font-semibold">
                                        {tab === 'incoming'
                                            ? 'Incoming transfers'
                                            : tab === 'dispatch'
                                              ? 'Outbound transfers'
                                              : 'Stock transfers'}
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        {tab === 'incoming'
                                            ? 'Open transfers on their way to this facility. Stock shows here only once received.'
                                            : tab === 'dispatch'
                                              ? 'Everything sent out from this facility.'
                                              : 'Transfers in and out of this facility.'}
                                    </p>
                                </div>
                                {can.transfer && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={createTransfer({
                                                query:
                                                    tab === 'incoming'
                                                        ? {
                                                              to_facility:
                                                                  facility.id,
                                                          }
                                                        : {
                                                              from_facility:
                                                                  facility.id,
                                                          },
                                            })}
                                        >
                                            <Plus className="size-4" />
                                            New transfer
                                        </Link>
                                    </Button>
                                )}
                            </div>
                            {!transfers || transfers.length === 0 ? (
                                <p className="text-muted-foreground p-5 text-sm">
                                    No transfers to show.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Number</TableHead>
                                            <TableHead>Direction</TableHead>
                                            <TableHead>From</TableHead>
                                            <TableHead>To</TableHead>
                                            <TableHead className="text-right">
                                                Lines
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Expected</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {transfers.map((t) => (
                                            <TableRow key={t.id}>
                                                <TableCell>
                                                    <Link
                                                        href={showTransfer(
                                                            t.id,
                                                        )}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {t.number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        variant={
                                                            t.direction === 'in'
                                                                ? 'info'
                                                                : 'muted'
                                                        }
                                                    >
                                                        {t.direction === 'in'
                                                            ? 'Inbound'
                                                            : 'Outbound'}
                                                    </StatusBadge>
                                                </TableCell>
                                                <TableCell>{t.from}</TableCell>
                                                <TableCell>{t.to}</TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {t.lines_count}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        variant={
                                                            TONE[t.tone] ??
                                                            'muted'
                                                        }
                                                    >
                                                        {t.status_label}
                                                    </StatusBadge>
                                                </TableCell>
                                                <TableCell>
                                                    {t.expected_at ?? '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </section>

                        {tab === 'incoming' && (
                            <section className="bg-card rounded-xl border">
                                <div className="border-b px-5 py-4">
                                    <h2 className="font-semibold">
                                        Goods receipts
                                    </h2>
                                    <p className="text-muted-foreground text-xs">
                                        Deliveries booked into this facility's
                                        stores.
                                    </p>
                                </div>
                                {!incomingReceipts ||
                                incomingReceipts.length === 0 ? (
                                    <p className="text-muted-foreground p-5 text-sm">
                                        No goods receipts yet.
                                    </p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Number</TableHead>
                                                <TableHead>Vendor</TableHead>
                                                <TableHead>Store</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Received</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {incomingReceipts.map((r) => (
                                                <TableRow key={r.id}>
                                                    <TableCell>
                                                        <Link
                                                            href={showReceipt(
                                                                r.id,
                                                            )}
                                                            className="font-medium underline-offset-4 hover:underline"
                                                        >
                                                            {r.number}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell>
                                                        {r.vendor ?? '—'}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-xs">
                                                        {r.store ?? '—'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge
                                                            variant={
                                                                r.status ===
                                                                'received'
                                                                    ? 'success'
                                                                    : r.status ===
                                                                        'cancelled'
                                                                      ? 'muted'
                                                                      : 'info'
                                                            }
                                                        >
                                                            {r.status_label}
                                                        </StatusBadge>
                                                    </TableCell>
                                                    <TableCell>
                                                        {r.received_at ?? '—'}
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </section>
                        )}
                    </>
                )}

                {tab === 'production' && production && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        <section className="bg-card rounded-xl border">
                            <div className="border-b px-5 py-4">
                                <h2 className="font-semibold">
                                    Production plans
                                </h2>
                            </div>
                            {production.plans.length === 0 ? (
                                <p className="text-muted-foreground p-5 text-sm">
                                    No plans at this facility yet.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Plan</TableHead>
                                            <TableHead>Formula</TableHead>
                                            <TableHead className="text-right">
                                                Batch
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {production.plans.map((p) => (
                                            <TableRow key={p.id}>
                                                <TableCell>
                                                    <Link
                                                        href={showPlan(p.id)}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {p.number}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {p.formula ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(p.quantity)} {p.uom}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge variant="info">
                                                        {p.status_label}
                                                    </StatusBadge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </section>
                        <section className="bg-card rounded-xl border">
                            <div className="border-b px-5 py-4">
                                <h2 className="font-semibold">
                                    Manufacturing orders
                                </h2>
                            </div>
                            {production.orders.length === 0 ? (
                                <p className="text-muted-foreground p-5 text-sm">
                                    No orders at this facility yet.
                                </p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Order</TableHead>
                                            <TableHead>Product</TableHead>
                                            <TableHead className="text-right">
                                                Batch
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {production.orders.map((o) => (
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
                                                <TableCell className="text-right tabular-nums">
                                                    {qty(o.quantity)} {o.uom}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        variant={
                                                            o.status ===
                                                            'completed'
                                                                ? 'success'
                                                                : o.status ===
                                                                    'cancelled'
                                                                  ? 'muted'
                                                                  : 'warning'
                                                        }
                                                    >
                                                        {o.status_label}
                                                    </StatusBadge>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </section>
                    </div>
                )}

                {tab === 'activity' && (
                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center gap-2 border-b px-5 py-4">
                            <ClipboardList className="text-muted-foreground size-4" />
                            <h2 className="font-semibold">Activity</h2>
                            <span className="text-muted-foreground text-xs">
                                Facility, store, assignment and transfer events
                                from the audit trail.
                            </span>
                        </div>
                        {!activity || activity.length === 0 ? (
                            <p className="text-muted-foreground p-5 text-sm">
                                Nothing recorded yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {activity.map((a) => (
                                    <li
                                        key={a.id}
                                        className="flex flex-wrap items-baseline justify-between gap-2 px-5 py-3 text-sm"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {a.actor}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                {a.action.toLowerCase()}
                                            </span>{' '}
                                            {a.subject && (
                                                <span className="font-medium">
                                                    {a.subject}
                                                </span>
                                            )}
                                            {a.description && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    — {a.description}
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {when(a.created_at)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}

                {tab === 'settings' && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        <section className="bg-card rounded-xl border p-6">
                            <h2 className="font-semibold">Opening stock</h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                {facility.opening_stock_enabled
                                    ? 'Opening stock can still be booked at this facility. Switch it off once the facility is live so stock only moves through receipts, production and transfers.'
                                    : 'Opening stock entry is closed. Re-open it only if go-live figures still need booking.'}
                            </p>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {can.opening_stock && (
                                    <Button asChild>
                                        <Link
                                            href={openingStock.create(
                                                facility.id,
                                            )}
                                        >
                                            Book opening stock
                                        </Link>
                                    </Button>
                                )}
                                {can.update && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            router.post(
                                                openingStockSwitch(facility.id)
                                                    .url,
                                                {
                                                    enabled:
                                                        !facility.opening_stock_enabled,
                                                },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {facility.opening_stock_enabled
                                            ? 'Close opening stock entry'
                                            : 'Re-open opening stock entry'}
                                    </Button>
                                )}
                            </div>
                        </section>
                        <section className="bg-card rounded-xl border p-6">
                            <h2 className="font-semibold">Status</h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                A facility is never deleted. Deactivating it
                                switches off its stores and stops new work;
                                every record stays readable. It must hold no
                                stock and have no open orders or transfers.
                            </p>
                            <div className="mt-4">
                                {can.deactivate &&
                                    (facility.is_active ? (
                                        <ConfirmDialog
                                            trigger={
                                                <Button variant="outline">
                                                    <PowerOff className="size-4" />
                                                    Deactivate facility
                                                </Button>
                                            }
                                            title={`Deactivate ${facility.name}?`}
                                            description="Its stores are switched off with it. Nothing already recorded changes and it can be reactivated later."
                                            confirmLabel="Deactivate"
                                            destructive
                                            action={() =>
                                                router.post(
                                                    deactivate(facility.id).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                    ) : (
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    activate(facility.id).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Power className="size-4" />
                                            Reactivate facility
                                        </Button>
                                    ))}
                            </div>
                        </section>
                    </div>
                )}
            </div>
        </>
    );
}

function StoresTab({
    facilityId,
    stores,
    options,
    can,
}: {
    facilityId: number;
    stores: StoreRow[];
    options: {
        categories: StoreCategoryOption[];
        managers: SelectOption[];
    } | null;
    can: { add_store: boolean };
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        store_category_id: '',
        name: '',
        code: '',
        default_location: '',
        manager_id: '',
        is_active: true,
    });
    const category = options?.categories.find(
        (c) => String(c.id) === form.data.store_category_id,
    );

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 className="font-semibold">Stores</h2>
                    <p className="text-muted-foreground text-xs">
                        Each store holds stock of one category. Add more at any
                        time; existing transactions are untouched.
                    </p>
                </div>
                {can.add_store && options && (
                    <Button
                        variant={adding ? 'ghost' : 'outline'}
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? (
                            <X className="size-4" />
                        ) : (
                            <Plus className="size-4" />
                        )}
                        {adding ? 'Cancel' : 'Add store'}
                    </Button>
                )}
            </div>

            {adding && options && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(facilityStores.store(facilityId).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                setAdding(false);
                            },
                        });
                    }}
                    className="grid gap-3 border-b p-5 sm:grid-cols-2 lg:grid-cols-5"
                >
                    <Field
                        label="Category"
                        htmlFor="new-store-category"
                        required
                        error={form.errors.store_category_id}
                    >
                        <Select
                            value={form.data.store_category_id}
                            onValueChange={(v) => {
                                const c = options.categories.find(
                                    (x) => String(x.id) === v,
                                );
                                form.setData({
                                    ...form.data,
                                    store_category_id: v,
                                    name:
                                        form.data.name ||
                                        (c ? `${c.name} Store` : ''),
                                });
                            }}
                        >
                            <SelectTrigger
                                id="new-store-category"
                                className="w-full"
                            >
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.categories.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.badge} · {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Store name"
                        htmlFor="new-store-name"
                        error={form.errors.name}
                    >
                        <Input
                            id="new-store-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                        />
                    </Field>
                    <Field
                        label="Code"
                        htmlFor="new-store-code"
                        error={form.errors.code}
                        hint={
                            category
                                ? `Blank → generated (…-${category.badge})`
                                : undefined
                        }
                    >
                        <Input
                            id="new-store-code"
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
                        label="Default location"
                        htmlFor="new-store-loc"
                        error={form.errors.default_location}
                    >
                        <Input
                            id="new-store-loc"
                            value={form.data.default_location}
                            onChange={(e) =>
                                form.setData('default_location', e.target.value)
                            }
                        />
                    </Field>
                    <div className="flex items-end">
                        <Button
                            type="submit"
                            disabled={
                                form.processing || !form.data.store_category_id
                            }
                        >
                            Add store
                        </Button>
                    </div>
                </form>
            )}

            {stores.length === 0 ? (
                <p className="text-muted-foreground p-5 text-sm">
                    No stores yet.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Store</TableHead>
                            <TableHead>Category</TableHead>
                            <TableHead>Manager</TableHead>
                            <TableHead className="text-right">Items</TableHead>
                            <TableHead className="text-right">
                                Locations
                            </TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="w-40"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {stores.map((s) => (
                            <TableRow key={s.id}>
                                <TableCell>
                                    <Link
                                        href={showStore(s.id)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {s.name}
                                    </Link>
                                    <div className="text-muted-foreground font-mono text-xs">
                                        {s.code}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <span className="bg-primary/10 text-primary mr-2 rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold">
                                        {s.badge}
                                    </span>
                                    {s.category ?? s.type}
                                    {s.is_quarantine && (
                                        <StatusBadge
                                            variant="warning"
                                            className="ml-2"
                                        >
                                            Held stock
                                        </StatusBadge>
                                    )}
                                </TableCell>
                                <TableCell>{s.manager ?? '—'}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {s.items}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {s.locations_count}
                                </TableCell>
                                <TableCell>
                                    <ActiveBadge active={s.is_active} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <div className="flex justify-end gap-1">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                        >
                                            <Link href={showStore(s.id)}>
                                                Open
                                            </Link>
                                        </Button>
                                        {s.can_deactivate &&
                                            (s.is_active ? (
                                                <ConfirmDialog
                                                    trigger={
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            Deactivate
                                                        </Button>
                                                    }
                                                    title={`Deactivate ${s.code}?`}
                                                    description="The store must hold no stock. Its history is kept and it can be reactivated later."
                                                    confirmLabel="Deactivate"
                                                    destructive
                                                    action={() =>
                                                        router.post(
                                                            deactivateStore(
                                                                s.id,
                                                            ).url,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                />
                                            ) : (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            activateStore(s.id)
                                                                .url,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Activate
                                                </Button>
                                            ))}
                                    </div>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}

function EmployeesTab({
    facilityId,
    stores,
    employees,
    options,
    can,
}: {
    facilityId: number;
    stores: StoreRow[];
    employees: EmployeeAssignmentRow[];
    options: { employees: SelectOption[] } | null;
    can: { assign: boolean };
}) {
    const [adding, setAdding] = useState(false);
    const form = useForm({
        user_id: '',
        store_id: '',
        is_primary: false,
        designation: '',
        effective_from: '',
    });

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 className="font-semibold">Employees</h2>
                    <p className="text-muted-foreground text-xs">
                        Who may act at this facility. An assignment to the whole
                        facility covers every store; one to a store covers that
                        store alone.
                    </p>
                </div>
                {can.assign && options && (
                    <Button
                        variant={adding ? 'ghost' : 'outline'}
                        onClick={() => setAdding(!adding)}
                    >
                        {adding ? (
                            <X className="size-4" />
                        ) : (
                            <UserPlus className="size-4" />
                        )}
                        {adding ? 'Cancel' : 'Assign employee'}
                    </Button>
                )}
            </div>

            {adding && options && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(facilityEmployees.store(facilityId).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                setAdding(false);
                            },
                        });
                    }}
                    className="grid gap-3 border-b p-5 sm:grid-cols-2 lg:grid-cols-5"
                >
                    <Field
                        label="Employee"
                        htmlFor="assign-user"
                        required
                        error={form.errors.user_id}
                    >
                        <Select
                            value={form.data.user_id}
                            onValueChange={(v) => form.setData('user_id', v)}
                        >
                            <SelectTrigger id="assign-user" className="w-full">
                                <SelectValue placeholder="Choose a person" />
                            </SelectTrigger>
                            <SelectContent>
                                {options.employees.map((u) => (
                                    <SelectItem
                                        key={u.value}
                                        value={String(u.value)}
                                    >
                                        {u.label}
                                        {u.description
                                            ? ` · ${u.description}`
                                            : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Scope"
                        htmlFor="assign-store"
                        error={form.errors.store_id}
                    >
                        <Select
                            value={form.data.store_id || 'all'}
                            onValueChange={(v) =>
                                form.setData('store_id', v === 'all' ? '' : v)
                            }
                        >
                            <SelectTrigger id="assign-store" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    Whole facility
                                </SelectItem>
                                {stores
                                    .filter((s) => s.is_active)
                                    .map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.badge} · {s.name}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Designation"
                        htmlFor="assign-designation"
                        error={form.errors.designation}
                    >
                        <Input
                            id="assign-designation"
                            value={form.data.designation}
                            onChange={(e) =>
                                form.setData('designation', e.target.value)
                            }
                            placeholder="e.g. Store Manager"
                        />
                    </Field>
                    <Field
                        label="From"
                        htmlFor="assign-from"
                        error={form.errors.effective_from}
                    >
                        <Input
                            id="assign-from"
                            type="date"
                            value={form.data.effective_from}
                            onChange={(e) =>
                                form.setData('effective_from', e.target.value)
                            }
                        />
                    </Field>
                    <div className="flex items-end gap-3">
                        <label className="flex items-center gap-2 pb-2 text-sm">
                            <Checkbox
                                checked={form.data.is_primary}
                                onCheckedChange={(c) =>
                                    form.setData('is_primary', c === true)
                                }
                            />
                            Primary
                        </label>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.user_id}
                        >
                            Assign
                        </Button>
                    </div>
                </form>
            )}

            {employees.length === 0 ? (
                <p className="text-muted-foreground p-5 text-sm">
                    Nobody is assigned here yet. People with company-wide roles
                    can still act at this facility.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Employee</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Designation</TableHead>
                            <TableHead>Scope</TableHead>
                            <TableHead>Since</TableHead>
                            <TableHead className="w-44"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {employees.map((a) => (
                            <TableRow key={a.id}>
                                <TableCell>
                                    <Link
                                        href={showUser(a.user_id ?? 0)}
                                        className="font-medium underline-offset-4 hover:underline"
                                    >
                                        {a.name}
                                    </Link>
                                    <div className="text-muted-foreground text-xs">
                                        {a.employee_code ?? a.email}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    {(a.roles ?? []).join(', ') || '—'}
                                </TableCell>
                                <TableCell>{a.designation ?? '—'}</TableCell>
                                <TableCell>
                                    {a.store ? (
                                        <StatusBadge variant="info">
                                            {a.store}
                                        </StatusBadge>
                                    ) : (
                                        <StatusBadge variant="muted">
                                            <Building2 className="mr-1 size-3" />
                                            Whole facility
                                        </StatusBadge>
                                    )}
                                    {a.is_primary && (
                                        <StatusBadge
                                            variant="success"
                                            className="ml-1"
                                        >
                                            <Star className="mr-1 size-3" />
                                            Primary
                                        </StatusBadge>
                                    )}
                                </TableCell>
                                <TableCell>{a.effective_from ?? '—'}</TableCell>
                                <TableCell className="text-right">
                                    {can.assign && (
                                        <div className="flex justify-end gap-1">
                                            {!a.is_primary && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            employeeAssignments.primary(
                                                                a.id,
                                                            ).url,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    Make primary
                                                </Button>
                                            )}
                                            <ConfirmDialog
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        End
                                                    </Button>
                                                }
                                                title={`End ${a.name}'s assignment?`}
                                                description="They will no longer be able to act at this facility or store. The assignment stays on record."
                                                confirmLabel="End assignment"
                                                destructive
                                                action={() =>
                                                    router.delete(
                                                        employeeAssignments.destroy(
                                                            a.id,
                                                        ).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            />
                                        </div>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </section>
    );
}

ShowFacility.layout = ({ facility }: { facility: FacilityView }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facilities & Warehouses', href: index() },
        { title: facility.name, href: show(facility.id) },
    ],
});
