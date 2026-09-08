import { Head, Link } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { DeleteDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { destroy, edit, index, show } from '@/routes/warehouses';
import type { Warehouse } from '@/types';

export default function ShowWarehouse({
    warehouse,
    can,
}: {
    warehouse: Warehouse;
    can: { update: boolean; delete: boolean };
}) {
    const address = [
        warehouse.address_line_1,
        warehouse.address_line_2,
        warehouse.city,
        warehouse.state,
        warehouse.pincode,
        warehouse.country,
    ]
        .filter(Boolean)
        .join(', ');

    return (
        <>
            <Head title={warehouse.code} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={warehouse.name}
                    description={warehouse.code}
                    actions={
                        <>
                            {can.update && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(warehouse.id)}>
                                        <Pencil className="size-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <DeleteDialog
                                    url={destroy(warehouse.id).url}
                                    label={warehouse.code}
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
                            <DetailItem label="Code">
                                {warehouse.code}
                            </DetailItem>
                            <DetailItem label="Name">
                                {warehouse.name}
                            </DetailItem>
                            <DetailItem label="Type">
                                {warehouse.type.replace(/_/g, ' ')}
                            </DetailItem>
                            <DetailItem label="Manager">
                                {warehouse.manager?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Address">
                                {address || '—'}
                            </DetailItem>
                            <DetailItem label="GSTIN">
                                {warehouse.gstin ?? '—'}
                            </DetailItem>
                            <DetailItem label="Notes">
                                {warehouse.notes ?? '—'}
                            </DetailItem>
                        </dl>
                    </section>

                    <section className="bg-card h-fit rounded-xl border p-6">
                        <h2 className="mb-5 font-semibold">Status</h2>
                        <div className="flex flex-wrap gap-2">
                            <ActiveBadge active={warehouse.is_active} />
                            {warehouse.is_quarantine && (
                                <StatusBadge variant="warning">
                                    Quarantine
                                </StatusBadge>
                            )}
                        </div>
                        {warehouse.is_quarantine && (
                            <p className="text-muted-foreground mt-3 text-xs">
                                Stock here cannot be issued to production or
                                sales until quality control releases it.
                            </p>
                        )}
                    </section>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Locations</h2>
                        <p className="text-muted-foreground text-sm">
                            Racks, bins and areas stock can be put away into.
                        </p>
                    </div>

                    {(warehouse.locations?.length ?? 0) === 0 ? (
                        <p className="text-muted-foreground p-5 text-sm">
                            No locations defined for this warehouse.
                        </p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>Code</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {warehouse.locations?.map((location) => (
                                    <TableRow key={location.id}>
                                        <TableCell className="font-medium">
                                            {location.code}
                                        </TableCell>
                                        <TableCell>{location.name}</TableCell>
                                        <TableCell className="capitalize">
                                            {location.type.replace(/_/g, ' ')}
                                        </TableCell>
                                        <TableCell>
                                            <ActiveBadge
                                                active={location.is_active}
                                            />
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

ShowWarehouse.layout = ({ warehouse }: { warehouse: Warehouse }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Warehouses', href: index() },
        { title: warehouse.code, href: show(warehouse.id) },
    ],
});
