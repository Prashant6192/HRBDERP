import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Field, FormSection } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import {
    index as facilitiesIndex,
    show as showFacility,
} from '@/routes/facilities';
import openingStock from '@/routes/facilities/opening-stock';
import type { SelectOption } from '@/types';

type ItemOption = SelectOption & {
    type: string;
    uom_id: number;
    uom: string | null;
    standard_cost: string | null;
};
type StoreOption = SelectOption & { badge: string; type: string };
type Line = {
    item_id: string;
    quantity: string;
    uom_id: string;
    batch_number: string;
    manufactured_at: string;
    expiry_at: string;
    unit_cost: string;
    remarks: string;
};

const EMPTY: Line = {
    item_id: '',
    quantity: '',
    uom_id: '',
    batch_number: '',
    manufactured_at: '',
    expiry_at: '',
    unit_cost: '',
    remarks: '',
};

export default function OpeningStock({
    facility,
    stores,
    preset_store,
    items,
    uoms,
    today,
}: {
    facility: {
        id: number;
        code: string;
        name: string;
        opening_stock_enabled: boolean;
        is_active: boolean;
    };
    stores: StoreOption[];
    preset_store: number | null;
    items: ItemOption[];
    uoms: (SelectOption & { dimension: string })[];
    today: string;
}) {
    const form = useForm<{
        warehouse_id: string;
        as_of: string;
        remarks: string;
        lines: Line[];
    }>({
        warehouse_id: preset_store
            ? String(preset_store)
            : stores.length === 1
              ? String(stores[0].value)
              : '',
        as_of: today,
        remarks: '',
        lines: [{ ...EMPTY }],
    });

    const store = stores.find(
        (s) => String(s.value) === form.data.warehouse_id,
    );
    const offered = items.filter((i) => {
        if (!store) return true;
        if (store.type === 'raw_material') return i.type === 'raw_material';
        if (store.type === 'packaging') return i.type === 'packaging_material';
        if (store.type === 'finished_goods') return i.type === 'finished_good';
        return true;
    });

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    const err = (i: number, field: string) =>
        (form.errors as Record<string, string>)[`lines.${i}.${field}`];

    return (
        <>
            <Head title={`Opening stock — ${facility.code}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Book opening stock"
                    description={`${facility.name}. Each line becomes a batch and one OPENING_BALANCE ledger posting; nothing here can be edited afterwards, only adjusted through the ledger.`}
                />

                {!facility.opening_stock_enabled && (
                    <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                        Opening stock entry is closed for this facility. An
                        administrator can re-open it under the facility's
                        Settings tab.
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(openingStock.store(facility.id).url, {
                            preserveScroll: true,
                        });
                    }}
                    className="space-y-6"
                >
                    <FormSection
                        title="Where and when"
                        description="One store per booking. Book the raw material store and the finished goods store separately."
                    >
                        <Field
                            label="Store"
                            htmlFor="warehouse_id"
                            required
                            error={form.errors.warehouse_id}
                        >
                            <Select
                                value={form.data.warehouse_id}
                                onValueChange={(v) =>
                                    form.setData('warehouse_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="warehouse_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    {stores.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={String(s.value)}
                                        >
                                            {s.badge} · {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="As of"
                            htmlFor="as_of"
                            required
                            error={form.errors.as_of}
                            hint="The date the count was taken."
                        >
                            <Input
                                id="as_of"
                                type="date"
                                value={form.data.as_of}
                                max={today}
                                onChange={(e) =>
                                    form.setData('as_of', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Remarks"
                            htmlFor="remarks"
                            error={form.errors.remarks}
                        >
                            <Input
                                id="remarks"
                                value={form.data.remarks}
                                onChange={(e) =>
                                    form.setData('remarks', e.target.value)
                                }
                                placeholder="Physical count on go-live"
                            />
                        </Field>
                    </FormSection>

                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">Lines</h2>
                                <p className="text-muted-foreground text-xs">
                                    Item, batch, quantity in the stock unit (or
                                    a unit that converts to it), dates and rate.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    form.setData('lines', [
                                        ...form.data.lines,
                                        { ...EMPTY },
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Add line
                            </Button>
                        </div>
                        {form.errors.lines && (
                            <p className="text-destructive px-5 pt-4 text-sm">
                                {form.errors.lines}
                            </p>
                        )}
                        <div className="divide-y">
                            {form.data.lines.map((line, i) => {
                                const item = items.find(
                                    (it) => String(it.value) === line.item_id,
                                );
                                return (
                                    <div
                                        key={i}
                                        className="grid gap-3 p-5 sm:grid-cols-2 lg:grid-cols-8"
                                    >
                                        <Field
                                            label="Item"
                                            htmlFor={`item-${i}`}
                                            required
                                            error={err(i, 'item_id')}
                                            className="lg:col-span-2"
                                        >
                                            <Select
                                                value={line.item_id}
                                                onValueChange={(v) => {
                                                    const it = items.find(
                                                        (x) =>
                                                            String(x.value) ===
                                                            v,
                                                    );
                                                    setLine(i, {
                                                        item_id: v,
                                                        uom_id: it
                                                            ? String(it.uom_id)
                                                            : '',
                                                        unit_cost:
                                                            line.unit_cost ||
                                                            (it?.standard_cost ??
                                                                ''),
                                                    });
                                                }}
                                            >
                                                <SelectTrigger
                                                    id={`item-${i}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Choose" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {offered.map((it) => (
                                                        <SelectItem
                                                            key={it.value}
                                                            value={String(
                                                                it.value,
                                                            )}
                                                        >
                                                            {it.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <Field
                                            label="Batch no."
                                            htmlFor={`batch-${i}`}
                                            error={err(i, 'batch_number')}
                                            hint="Blank → generated"
                                        >
                                            <Input
                                                id={`batch-${i}`}
                                                value={line.batch_number}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        batch_number:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Quantity"
                                            htmlFor={`qty-${i}`}
                                            required
                                            error={err(i, 'quantity')}
                                        >
                                            <Input
                                                id={`qty-${i}`}
                                                type="number"
                                                step="any"
                                                min="0"
                                                value={line.quantity}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        quantity:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Unit"
                                            htmlFor={`uom-${i}`}
                                            error={err(i, 'uom_id')}
                                        >
                                            <Select
                                                value={line.uom_id}
                                                onValueChange={(v) =>
                                                    setLine(i, { uom_id: v })
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`uom-${i}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue
                                                        placeholder={
                                                            item?.uom ?? 'Unit'
                                                        }
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {uoms.map((u) => (
                                                        <SelectItem
                                                            key={u.value}
                                                            value={String(
                                                                u.value,
                                                            )}
                                                        >
                                                            {u.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <Field
                                            label="Mfg date"
                                            htmlFor={`mfg-${i}`}
                                            error={err(i, 'manufactured_at')}
                                        >
                                            <Input
                                                id={`mfg-${i}`}
                                                type="date"
                                                value={line.manufactured_at}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        manufactured_at:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <Field
                                            label="Expiry"
                                            htmlFor={`exp-${i}`}
                                            error={err(i, 'expiry_at')}
                                        >
                                            <Input
                                                id={`exp-${i}`}
                                                type="date"
                                                value={line.expiry_at}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        expiry_at:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                        <div className="flex items-end gap-2">
                                            <Field
                                                label="Rate ₹"
                                                htmlFor={`rate-${i}`}
                                                error={err(i, 'unit_cost')}
                                            >
                                                <Input
                                                    id={`rate-${i}`}
                                                    type="number"
                                                    step="any"
                                                    min="0"
                                                    value={line.unit_cost}
                                                    onChange={(e) =>
                                                        setLine(i, {
                                                            unit_cost:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                className="mb-0.5"
                                                disabled={
                                                    form.data.lines.length === 1
                                                }
                                                onClick={() =>
                                                    form.setData(
                                                        'lines',
                                                        form.data.lines.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </div>
                                        <Field
                                            label="Remarks"
                                            htmlFor={`rem-${i}`}
                                            error={err(i, 'remarks')}
                                            className="sm:col-span-2 lg:col-span-8"
                                        >
                                            <Input
                                                id={`rem-${i}`}
                                                value={line.remarks}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        remarks: e.target.value,
                                                    })
                                                }
                                            />
                                        </Field>
                                    </div>
                                );
                            })}
                        </div>
                    </section>

                    <div className="flex items-center gap-3">
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                !facility.opening_stock_enabled ||
                                !form.data.warehouse_id
                            }
                        >
                            Post opening stock
                        </Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href={showFacility(facility.id)}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

OpeningStock.layout = ({
    facility,
}: {
    facility: { id: number; name: string };
}) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facilities & Warehouses', href: facilitiesIndex() },
        { title: facility.name, href: showFacility(facility.id) },
        { title: 'Opening stock', href: '#' },
    ],
});
