import { Head, Link, useForm } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { Field, FormSection } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes';
import cartons from '@/routes/lots/cartons';
import { index, show } from '@/routes/lots';
import type { InventoryLot } from '@/types';

type LotWithPack = InventoryLot & {
    item?: InventoryLot['item'] & {
        net_content?: string | null;
        net_content_uom?: { id: number; code: string } | null;
        mrp?: string | null;
    };
};

type Plan = {
    boxes: number;
    units_per_box: number;
    gross_weight_kg: string | null;
    start_box: number;
    net_quantity: string | null;
    remarks: string | null;
    planned_at?: string;
};

export default function CartonLabels({
    lot,
    plan,
    printable,
}: {
    lot: LotWithPack;
    plan: Plan | null;
    printable: boolean;
}) {
    const netDefault =
        lot.item?.net_content && lot.item?.net_content_uom?.code
            ? `${Number(lot.item.net_content)} ${lot.item.net_content_uom.code}`
            : '';

    const form = useForm({
        boxes: plan ? String(plan.boxes) : '',
        units_per_box: plan ? String(plan.units_per_box) : '',
        gross_weight_kg: plan?.gross_weight_kg ?? '',
        start_box: plan ? String(plan.start_box) : '1',
        net_quantity: plan?.net_quantity ?? netDefault,
        remarks: plan?.remarks ?? '',
    });

    const totalUnits =
        Number(form.data.boxes) > 0 && Number(form.data.units_per_box) > 0
            ? Number(form.data.boxes) * Number(form.data.units_per_box)
            : null;

    return (
        <>
            <Head title={`Carton labels — ${lot.batch_number}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Carton labels"
                    description={`${lot.item?.name ?? ''} · batch ${lot.batch_number}. Record how the batch is boxed once; every print after that is identical, so box 7 is always box 7.`}
                    actions={
                        plan &&
                        printable && (
                            <Button asChild>
                                <a
                                    href={cartons.print(lot.id).url}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer className="size-4" />
                                    Print {plan.boxes} A5 label
                                    {plan.boxes === 1 ? '' : 's'}
                                </a>
                            </Button>
                        )
                    }
                />

                {!printable && (
                    <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                        This batch has not been released by QC yet. The plan can
                        be saved now; labels print once it passes.
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(cartons.store(lot.id).url, {
                            preserveScroll: true,
                        });
                    }}
                    className="space-y-6"
                >
                    <FormSection
                        title="How the batch is boxed"
                        description="Entered by the finished goods store when the batch comes in."
                    >
                        <Field
                            label="Number of boxes"
                            htmlFor="boxes"
                            required
                            error={form.errors.boxes}
                        >
                            <Input
                                id="boxes"
                                type="number"
                                min={1}
                                value={form.data.boxes}
                                onChange={(e) =>
                                    form.setData('boxes', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Units per box"
                            htmlFor="units_per_box"
                            required
                            error={form.errors.units_per_box}
                            hint={
                                totalUnits
                                    ? `${totalUnits.toLocaleString()} units in total · batch holds ${Number(lot.initial_quantity).toLocaleString()} ${lot.item?.stock_uom?.code ?? ''}`
                                    : undefined
                            }
                        >
                            <Input
                                id="units_per_box"
                                type="number"
                                min={1}
                                value={form.data.units_per_box}
                                onChange={(e) =>
                                    form.setData(
                                        'units_per_box',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Gross weight per box (kg)"
                            htmlFor="gross_weight_kg"
                            error={form.errors.gross_weight_kg}
                        >
                            <Input
                                id="gross_weight_kg"
                                type="number"
                                step="any"
                                min={0}
                                value={form.data.gross_weight_kg}
                                onChange={(e) =>
                                    form.setData(
                                        'gross_weight_kg',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="First box number"
                            htmlFor="start_box"
                            error={form.errors.start_box}
                            hint="Usually 1. Use a higher number when adding boxes to a batch already labelled."
                        >
                            <Input
                                id="start_box"
                                type="number"
                                min={1}
                                value={form.data.start_box}
                                onChange={(e) =>
                                    form.setData('start_box', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Net quantity per unit"
                            htmlFor="net_quantity"
                            error={form.errors.net_quantity}
                            hint="From the product master; change only if the pack differs."
                        >
                            <Input
                                id="net_quantity"
                                value={form.data.net_quantity}
                                onChange={(e) =>
                                    form.setData('net_quantity', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Remark on label"
                            htmlFor="remarks"
                            error={form.errors.remarks}
                        >
                            <Input
                                id="remarks"
                                value={form.data.remarks}
                                onChange={(e) =>
                                    form.setData('remarks', e.target.value)
                                }
                                placeholder="e.g. Fragile · Keep upright"
                            />
                        </Field>
                    </FormSection>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Save carton plan
                        </Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href={show(lot.id)}>Back to batch</Link>
                        </Button>
                    </div>
                </form>

                <section className="bg-card rounded-xl border p-6 text-sm">
                    <h2 className="font-semibold">
                        What prints on each A5 label
                    </h2>
                    <p className="text-muted-foreground mt-1">
                        Product name, net quantity, batch number, manufacturing
                        date, expiry, gross weight, MRP and the box number
                        (&ldquo;Box 3 of 12&rdquo;). One page per box, sized for
                        A5 landscape.
                    </p>
                </section>
            </div>
        </>
    );
}

CartonLabels.layout = ({ lot }: { lot: LotWithPack }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Batches', href: index() },
        { title: lot.batch_number, href: show(lot.id) },
        { title: 'Carton labels', href: '#' },
    ],
});
