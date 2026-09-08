import { Head, Link } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { DeleteDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/vendors';
import type { Vendor } from '@/types';

export default function ShowVendor({
    vendor,
    can,
}: {
    vendor: Vendor;
    can: { update: boolean; delete: boolean };
}) {
    const address = [
        vendor.address_line_1,
        vendor.address_line_2,
        vendor.city,
        vendor.state,
        vendor.pincode,
        vendor.country,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <Head title={vendor.code} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={vendor.name}
                    description={vendor.code}
                    actions={
                        <>
                            {can.update && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(vendor.id)}>
                                        <Pencil className="size-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <DeleteDialog
                                    url={destroy(vendor.id).url}
                                    label={vendor.code}
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
                            <DetailItem label="Legal name">
                                {vendor.legal_name ?? '—'}
                            </DetailItem>
                            <DetailItem label="GSTIN">
                                {vendor.gstin ?? '—'}
                            </DetailItem>
                            <DetailItem label="PAN">
                                {vendor.pan ?? '—'}
                            </DetailItem>
                            <DetailItem label="Supplies">
                                <span className="capitalize">
                                    {vendor.supply_type.replace(/_/g, ' ')}
                                </span>
                            </DetailItem>
                            <DetailItem label="Contact">
                                {vendor.contact_person ?? '—'}
                            </DetailItem>
                            <DetailItem label="Email">
                                {vendor.email ?? '—'}
                            </DetailItem>
                            <DetailItem label="Phone">
                                {vendor.phone ?? '—'}
                            </DetailItem>
                            <DetailItem label="Address">
                                {address || '—'}
                            </DetailItem>
                            <DetailItem label="Payment terms">
                                {vendor.payment_terms_days
                                    ? `${vendor.payment_terms_days} days`
                                    : '—'}
                            </DetailItem>
                            <DetailItem label="Credit limit">
                                {vendor.credit_limit
                                    ? `₹${Number(vendor.credit_limit).toLocaleString('en-IN')}`
                                    : '—'}
                            </DetailItem>
                            <DetailItem label="Notes">
                                {vendor.notes ?? '—'}
                            </DetailItem>
                        </dl>
                    </section>

                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-4 font-semibold">Status</h2>
                        <div className="flex flex-wrap gap-2">
                            <ActiveBadge active={vendor.is_active} />
                            {vendor.is_approved ? (
                                <StatusBadge variant="success">
                                    Approved
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="warning">
                                    Unapproved
                                </StatusBadge>
                            )}
                        </div>
                        {!vendor.is_approved && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                Purchase orders cannot be raised against an
                                unapproved vendor.
                            </p>
                        )}
                    </section>
                </div>
            </div>
        </>
    );
}

ShowVendor.layout = ({ vendor }: { vendor: Vendor }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Vendors', href: index() },
        { title: vendor.code, href: show(vendor.id) },
    ],
});
