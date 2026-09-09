import { Head, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
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
import { create, index, store } from '@/routes/plans';
import type { SelectOption } from '@/types';

type FormulaOption = SelectOption & {
    product: string | null;
    net_content: string | null;
    net_content_uom: string | null;
    version: number | null;
    batch_uom_id: number | null;
    batch_uom: string | null;
};

type UomOption = SelectOption & { dimension: string };

export default function CreatePlan({
    formulas,
    uoms,
    today,
}: {
    formulas: FormulaOption[];
    uoms: UomOption[];
    today: string;
}) {
    const form = useForm({
        formula_id: '',
        quantity: '',
        uom_id: '',
        planned_start_date: '',
        notes: '',
    });

    const formula = useMemo(
        () => formulas.find((f) => String(f.value) === form.data.formula_id),
        [formulas, form.data.formula_id],
    );

    const chooseFormula = (id: string) => {
        const chosen = formulas.find((f) => String(f.value) === id);
        form.setData({
            ...form.data,
            formula_id: id,
            uom_id: chosen?.batch_uom_id
                ? String(chosen.batch_uom_id)
                : form.data.uom_id,
        });
    };

    const estimatedUnits = (() => {
        if (!formula?.net_content || !form.data.quantity) {
            return null;
        }

        const uom = uoms.find((u) => String(u.value) === form.data.uom_id);
        const q = Number(form.data.quantity);

        if (!uom || !Number.isFinite(q)) {
            return null;
        }

        // A rough figure for the screen only (1 g = 1 ml; kg/L = ×1000).
        // The server works it out properly with the product's density.
        const code = String(uom.label).split(' ')[0];
        const base = code === 'KG' || code === 'L' ? q * 1000 : q;
        const perUnit = Number(formula.net_content);

        return perUnit > 0 ? Math.floor(base / perUnit) : null;
    })();

    return (
        <>
            <Head title="Plan a batch" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Plan a batch"
                    description="Pick the formula and how much to make. The stores are checked the moment you save."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(store().url);
                    }}
                    className="space-y-6"
                >
                    <FormSection title="What to make">
                        <Field
                            label="Formula"
                            htmlFor="formula_id"
                            required
                            error={form.errors.formula_id}
                            hint="Only formulas with an active recipe are offered."
                        >
                            <Select
                                value={form.data.formula_id}
                                onValueChange={chooseFormula}
                            >
                                <SelectTrigger
                                    id="formula_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a formula" />
                                </SelectTrigger>
                                <SelectContent>
                                    {formulas.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={String(f.value)}
                                        >
                                            {f.label}
                                            {f.version
                                                ? ` · v${f.version}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Product"
                            htmlFor="product"
                            hint="Comes from the formula; packaging is planned from its pack list."
                        >
                            <Input
                                id="product"
                                value={
                                    formula?.product ??
                                    (formula ? 'No product linked' : '')
                                }
                                readOnly
                                className="bg-muted/40"
                            />
                        </Field>

                        <Field
                            label="Batch quantity"
                            htmlFor="quantity"
                            required
                            error={form.errors.quantity ?? form.errors.uom_id}
                        >
                            <div className="flex gap-2">
                                <Input
                                    id="quantity"
                                    inputMode="decimal"
                                    value={form.data.quantity}
                                    onChange={(e) =>
                                        form.setData('quantity', e.target.value)
                                    }
                                    placeholder="e.g. 100"
                                    required
                                />
                                <Select
                                    value={form.data.uom_id}
                                    onValueChange={(v) =>
                                        form.setData('uom_id', v)
                                    }
                                >
                                    <SelectTrigger className="w-44">
                                        <SelectValue placeholder="Unit" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {uoms.map((u) => (
                                            <SelectItem
                                                key={u.value}
                                                value={String(u.value)}
                                            >
                                                {u.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {estimatedUnits !== null && formula && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    About {estimatedUnits.toLocaleString()} ×{' '}
                                    {Number(formula.net_content)}{' '}
                                    {formula.net_content_uom} units.
                                </p>
                            )}
                        </Field>

                        <Field
                            label="Planned start"
                            htmlFor="planned_start_date"
                            error={form.errors.planned_start_date}
                            hint="Becomes the needed-by date on the material requests."
                        >
                            <Input
                                id="planned_start_date"
                                type="date"
                                min={today}
                                value={form.data.planned_start_date}
                                onChange={(e) =>
                                    form.setData(
                                        'planned_start_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>

                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </FormSection>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Checking stores…'
                                : 'Save and check stores'}
                        </Button>
                        <p className="text-muted-foreground text-sm">
                            You will see every material with what the store
                            holds, what is short, and what to order.
                        </p>
                    </div>
                </form>
            </div>
        </>
    );
}

CreatePlan.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Production plans', href: index() },
        { title: 'Plan a batch', href: create() },
    ],
};
