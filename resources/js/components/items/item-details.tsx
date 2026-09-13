import { Link } from '@inertiajs/react';
import { ClipboardCheck, PackagePlus, Pencil, Trash2 } from 'lucide-react';
import { DeleteDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { show as showInspection } from '@/routes/qc';
import type { Item } from '@/types';

export type ItemStock = {
    on_hand: string;
    available: string;
    in_quarantine: string;
    awaiting_qc: {
        id: number;
        number: string;
        batch: string | null;
        quantity: string;
        status: string;
    }[];
};

function money(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `₹${Number(value).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`;
}

function quantity(value: string | null, unit?: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toLocaleString('en-IN')}${unit ? ` ${unit}` : ''}`;
}

export function ItemDetails({
    item,
    can,
    editUrl,
    deleteUrl,
    receiveUrl,
    stock,
}: {
    item: Item;
    can: {
        update: boolean;
        delete: boolean;
        receive?: boolean;
        view_stock?: boolean;
        view_qc?: boolean;
    };
    editUrl: string;
    deleteUrl: string;
    /** Where "Receive stock" goes: the goods receipt form with this item preset. */
    receiveUrl?: string;
    stock?: ItemStock | null;
}) {
    const isProduct = item.type === 'finished_good';
    const stockUnit = item.stock_uom?.code;

    return (
        <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
            <PageHeader
                title={item.name}
                description={item.code}
                actions={
                    <>
                        {can.receive && receiveUrl && (
                            <Button asChild>
                                <Link href={receiveUrl}>
                                    <PackagePlus className="size-4" />
                                    Receive stock
                                </Link>
                            </Button>
                        )}
                        {can.update && (
                            <Button variant="outline" asChild>
                                <Link href={editUrl}>
                                    <Pencil className="size-4" />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <DeleteDialog
                                url={deleteUrl}
                                label={item.code}
                                trigger={
                                    <Button variant="outline">
                                        <Trash2 className="size-4" />
                                        Remove
                                    </Button>
                                }
                            />
                        )}
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <section className="bg-card rounded-xl border p-6 lg:col-span-2">
                    <h2 className="mb-5 font-semibold">Details</h2>
                    <dl className="grid gap-5 sm:grid-cols-2">
                        <DetailItem label="Code">{item.code}</DetailItem>
                        <DetailItem label="Name">{item.name}</DetailItem>
                        {item.inci_name && (
                            <DetailItem label="INCI name">
                                {item.inci_name}
                            </DetailItem>
                        )}
                        <DetailItem label="Category">
                            {item.category?.name ?? '—'}
                        </DetailItem>
                        <DetailItem label="Stock unit">
                            {item.stock_uom
                                ? `${item.stock_uom.code} — ${item.stock_uom.name}`
                                : '—'}
                        </DetailItem>
                        <DetailItem label="Purchase unit">
                            {item.purchase_uom?.code ?? 'Same as stock unit'}
                        </DetailItem>
                        {item.density_g_per_ml && (
                            <DetailItem label="Density">
                                {item.density_g_per_ml} g/ml
                            </DetailItem>
                        )}
                        {isProduct && (
                            <>
                                <DetailItem label="Brand">
                                    {item.brand ?? '—'}
                                </DetailItem>
                                <DetailItem label="MRP">
                                    {money(item.mrp)}
                                </DetailItem>
                                <DetailItem label="Net content">
                                    {quantity(
                                        item.net_content,
                                        item.net_content_uom?.code,
                                    )}
                                </DetailItem>
                                <DetailItem label="Barcode">
                                    {item.barcode ?? '—'}
                                </DetailItem>
                            </>
                        )}
                        <DetailItem label="HSN code">
                            {item.hsn_code ?? '—'}
                        </DetailItem>
                        <DetailItem label="GST rate">
                            {item.gst_rate ? `${Number(item.gst_rate)}%` : '—'}
                        </DetailItem>
                        <DetailItem label="Standard cost">
                            {item.standard_cost
                                ? `${money(item.standard_cost)} per ${stockUnit ?? 'unit'}`
                                : '—'}
                        </DetailItem>
                        <DetailItem label="Description">
                            {item.description ?? '—'}
                        </DetailItem>
                    </dl>
                </section>

                <div className="space-y-6">
                    <section className="bg-card rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Handling</h2>
                        <div className="flex flex-wrap gap-2">
                            <ActiveBadge active={item.is_active} />
                            {item.is_batch_tracked && (
                                <StatusBadge variant="info">
                                    Batch tracked
                                </StatusBadge>
                            )}
                            {item.requires_qc && (
                                <StatusBadge variant="warning">
                                    QC required
                                </StatusBadge>
                            )}
                        </div>
                    </section>

                    {can.view_stock && stock && (
                        <section className="bg-card rounded-xl border p-6">
                            <h2 className="mb-4 font-semibold">Stock now</h2>
                            <dl className="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                                <DetailItem label="Free in stores">
                                    {quantity(stock.available, stockUnit)}
                                </DetailItem>
                                <DetailItem label="Held in quarantine">
                                    {quantity(stock.in_quarantine, stockUnit)}
                                </DetailItem>
                                <DetailItem label="On hand in total">
                                    {quantity(stock.on_hand, stockUnit)}
                                </DetailItem>
                            </dl>
                            {stock.awaiting_qc.length > 0 && (
                                <div className="mt-4 border-t pt-4">
                                    <p className="text-muted-foreground mb-2 text-xs tracking-wide uppercase">
                                        Awaiting QC
                                    </p>
                                    <ul className="space-y-1 text-sm">
                                        {stock.awaiting_qc.map((i) => (
                                            <li
                                                key={i.id}
                                                className="flex items-center justify-between gap-2"
                                            >
                                                {can.view_qc ? (
                                                    <Link
                                                        href={showInspection(
                                                            i.id,
                                                        )}
                                                        className="inline-flex items-center gap-1 font-medium underline-offset-4 hover:underline"
                                                    >
                                                        <ClipboardCheck className="size-3" />
                                                        {i.number}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium">
                                                        {i.number}
                                                    </span>
                                                )}
                                                <span className="text-muted-foreground font-mono text-xs">
                                                    {i.batch ?? '—'} ·{' '}
                                                    {quantity(
                                                        i.quantity,
                                                        stockUnit,
                                                    )}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {stock.awaiting_qc.length === 0 &&
                                Number(stock.in_quarantine) === 0 &&
                                can.receive && (
                                    <p className="text-muted-foreground mt-3 text-xs">
                                        A new delivery goes to quarantine first;
                                        QC releases it into the store.
                                    </p>
                                )}
                        </section>
                    )}

                    <section className="bg-card rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Stock control</h2>
                        <dl className="space-y-4">
                            <DetailItem label="Reorder level">
                                {quantity(item.reorder_level, stockUnit)}
                            </DetailItem>
                            <DetailItem label="Minimum">
                                {quantity(item.minimum_stock, stockUnit)}
                            </DetailItem>
                            <DetailItem label="Maximum">
                                {quantity(item.maximum_stock, stockUnit)}
                            </DetailItem>
                            <DetailItem label="Lead time">
                                {item.lead_time_days
                                    ? `${item.lead_time_days} days`
                                    : '—'}
                            </DetailItem>
                            <DetailItem label="Shelf life">
                                {item.shelf_life_days
                                    ? `${item.shelf_life_days} days`
                                    : '—'}
                            </DetailItem>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    );
}
