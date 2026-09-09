import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo } from 'react';
import { Field, FormSection } from '@/components/form-field';
import { FormulaLockChip } from '@/components/formula-lock-chip';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
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
import { pct, sumPercent } from '@/lib/formulas';
import { dashboard } from '@/routes';
import { create, edit, index, show, store, update } from '@/routes/formulas';
import type { SelectOption } from '@/types';

type MaterialOption = SelectOption & {
    inci_name: string | null;
    stock_uom: string | null;
};

type UomOption = SelectOption & { dimension: string };

type Line = {
    item_id: string;
    inci_name: string;
    percentage: string;
    is_qs: boolean;
    qs_note: string;
    grade: string;
    phase: string;
    purpose: string;
    notes: string;
};

type FormulaHead = {
    id: number;
    code: string;
    name: string;
    product_id: number | null;
    description: string | null;
    status: string;
};

type VersionHead = {
    id: number;
    version_number: number;
    batch_size: string;
    batch_uom_id: number;
    notes: string | null;
    change_summary: string | null;
};

const NONE = '__none__';

const emptyLine = (): Line => ({
    item_id: '',
    inci_name: '',
    percentage: '',
    is_qs: false,
    qs_note: '',
    grade: '',
    phase: '',
    purpose: '',
    notes: '',
});

export default function FormulaForm({
    mode,
    formula,
    version,
    lines,
    products,
    materials,
    uoms,
    grades,
}: {
    mode: 'create' | 'edit';
    formula: FormulaHead | null;
    version: VersionHead | null;
    lines: Line[];
    products: SelectOption[];
    materials: MaterialOption[];
    uoms: UomOption[];
    grades: SelectOption[];
}) {
    const defaultUom =
        uoms.find((u) => String(u.label).startsWith('G '))?.value ??
        uoms[0]?.value ??
        '';

    const form = useForm({
        name: formula?.name ?? '',
        product_id: formula?.product_id ? String(formula.product_id) : '',
        description: formula?.description ?? '',
        batch_size: version?.batch_size ?? '100',
        batch_uom_id: String(version?.batch_uom_id ?? defaultUom),
        notes: version?.notes ?? '',
        change_summary: version?.change_summary ?? '',
        lines: lines.length > 0 ? lines : [emptyLine()],
    });

    const errors = form.errors as Record<string, string | undefined>;

    const totals = useMemo(() => {
        const fixed = sumPercent(
            form.data.lines.filter((l) => !l.is_qs).map((l) => l.percentage),
        );
        const qsLines = form.data.lines.filter((l) => l.is_qs).length;
        const asRequired = form.data.lines.filter(
            (l) => !l.is_qs && l.percentage.trim() === '' && l.item_id !== '',
        ).length;

        return {
            fixed,
            remaining: 100 - fixed,
            qsLines,
            asRequired,
            complete: qsLines === 1 || Math.abs(100 - fixed) < 0.000001,
        };
    }, [form.data.lines]);

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((line, j) =>
                j === i ? { ...line, ...patch } : line,
            ),
        );

    const chooseMaterial = (i: number, itemId: string) => {
        const material = materials.find((m) => String(m.value) === itemId);
        setLine(i, {
            item_id: itemId,
            inci_name: material?.inci_name ?? form.data.lines[i].inci_name,
        });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.transform((data) => ({
            ...data,
            product_id: data.product_id === '' ? null : data.product_id,
            lines: data.lines.map((line) => ({
                ...line,
                percentage: line.is_qs ? '' : line.percentage,
                grade: line.grade === NONE ? '' : line.grade,
            })),
        }));

        if (mode === 'create') {
            form.post(store().url, { preserveScroll: true });
        } else if (formula) {
            form.put(update(formula.id).url, { preserveScroll: true });
        }
    };

    const title =
        mode === 'create'
            ? 'New formula'
            : `${formula?.code} · edit draft v${version?.version_number}`;

    return (
        <>
            <Head title={title} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={title}
                    description={
                        mode === 'create'
                            ? 'Enter the recipe as percentages of a reference batch. It is saved as a draft until someone with approval rights activates it.'
                            : 'Changes are saved into the open draft. The active recipe stays as it is until this draft is activated.'
                    }
                    actions={<FormulaLockChip />}
                />

                <form onSubmit={submit} className="space-y-6">
                    <FormSection
                        title="Formula"
                        description="What the recipe makes."
                    >
                        <Field
                            label="Name"
                            htmlFor="name"
                            required
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="e.g. Hydrating Face Wash"
                                required
                            />
                        </Field>

                        <Field
                            label="Product"
                            htmlFor="product_id"
                            error={errors.product_id}
                            hint="Optional. Link the finished good this recipe makes."
                        >
                            <Select
                                value={form.data.product_id || NONE}
                                onValueChange={(v) =>
                                    form.setData(
                                        'product_id',
                                        v === NONE ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger
                                    id="product_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="No product linked" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        No product linked
                                    </SelectItem>
                                    {products.map((p) => (
                                        <SelectItem
                                            key={p.value}
                                            value={String(p.value)}
                                        >
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Reference batch"
                            htmlFor="batch_size"
                            required
                            error={errors.batch_size ?? errors.batch_uom_id}
                            hint="The batch the percentages describe — usually 100 g or 100 ml."
                        >
                            <div className="flex gap-2">
                                <Input
                                    id="batch_size"
                                    inputMode="decimal"
                                    value={form.data.batch_size}
                                    onChange={(e) =>
                                        form.setData(
                                            'batch_size',
                                            e.target.value,
                                        )
                                    }
                                    className="w-32"
                                    required
                                />
                                <Select
                                    value={form.data.batch_uom_id}
                                    onValueChange={(v) =>
                                        form.setData('batch_uom_id', v)
                                    }
                                >
                                    <SelectTrigger className="w-full">
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
                        </Field>

                        {mode === 'edit' && (
                            <Field
                                label="What changed in this version"
                                htmlFor="change_summary"
                                error={errors.change_summary}
                            >
                                <Input
                                    id="change_summary"
                                    value={form.data.change_summary}
                                    onChange={(e) =>
                                        form.setData(
                                            'change_summary',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Raised glycerin to 3%"
                                />
                            </Field>
                        )}

                        <Field
                            label="Description"
                            htmlFor="description"
                            error={errors.description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="description"
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                        </Field>

                        <Field
                            label="Process notes"
                            htmlFor="notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                            hint="Kept with the version: phases, temperatures, order of addition."
                        >
                            <textarea
                                id="notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                rows={3}
                                className="border-input placeholder:text-muted-foreground focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                            />
                        </Field>
                    </FormSection>

                    <section className="bg-card rounded-xl border">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">Ingredients</h2>
                                <p className="text-muted-foreground text-sm">
                                    One line per material. Mark the filler
                                    (usually water) as QS; leave the percentage
                                    blank for anything dosed &ldquo;as
                                    required&rdquo;.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    form.setData('lines', [
                                        ...form.data.lines,
                                        emptyLine(),
                                    ])
                                }
                            >
                                <Plus className="size-4" />
                                Add ingredient
                            </Button>
                        </div>

                        <InputError
                            message={errors.lines}
                            className="px-5 pt-3"
                        />

                        <div className="divide-y">
                            {form.data.lines.map((line, i) => (
                                <div
                                    key={i}
                                    className="grid gap-3 p-5 lg:grid-cols-12"
                                >
                                    <div className="space-y-1.5 lg:col-span-4">
                                        <span className="text-sm font-medium">
                                            {i + 1}. Material
                                        </span>
                                        <Select
                                            value={line.item_id}
                                            onValueChange={(v) =>
                                                chooseMaterial(i, v)
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select a raw material" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {materials.map((m) => (
                                                    <SelectItem
                                                        key={m.value}
                                                        value={String(m.value)}
                                                    >
                                                        {m.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError
                                            message={
                                                errors[`lines.${i}.item_id`]
                                            }
                                        />
                                    </div>

                                    <div className="space-y-1.5 lg:col-span-3">
                                        <span className="text-sm font-medium">
                                            INCI name
                                        </span>
                                        <Input
                                            value={line.inci_name}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    inci_name: e.target.value,
                                                })
                                            }
                                            placeholder="As on the label"
                                        />
                                    </div>

                                    <div className="space-y-1.5 lg:col-span-2">
                                        <span className="text-sm font-medium">
                                            % of batch
                                        </span>
                                        <Input
                                            inputMode="decimal"
                                            value={
                                                line.is_qs
                                                    ? ''
                                                    : line.percentage
                                            }
                                            disabled={line.is_qs}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    percentage: e.target.value,
                                                })
                                            }
                                            placeholder={
                                                line.is_qs
                                                    ? 'QS'
                                                    : 'blank = as required'
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[`lines.${i}.percentage`]
                                            }
                                        />
                                        <label className="flex items-center gap-2 text-xs">
                                            <Checkbox
                                                checked={line.is_qs}
                                                onCheckedChange={(checked) =>
                                                    setLine(i, {
                                                        is_qs: checked === true,
                                                        percentage:
                                                            checked === true
                                                                ? ''
                                                                : line.percentage,
                                                    })
                                                }
                                            />
                                            QS to 100 (filler)
                                        </label>
                                    </div>

                                    <div className="space-y-1.5 lg:col-span-1">
                                        <span className="text-sm font-medium">
                                            Grade
                                        </span>
                                        <Select
                                            value={line.grade || NONE}
                                            onValueChange={(v) =>
                                                setLine(i, {
                                                    grade: v === NONE ? '' : v,
                                                })
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="—" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NONE}>
                                                    —
                                                </SelectItem>
                                                {grades.map((g) => (
                                                    <SelectItem
                                                        key={g.value}
                                                        value={String(g.value)}
                                                    >
                                                        {g.value}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1.5 lg:col-span-2">
                                        <span className="text-sm font-medium">
                                            Function
                                        </span>
                                        <Input
                                            value={line.purpose}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    purpose: e.target.value,
                                                })
                                            }
                                            placeholder="e.g. Humectant"
                                        />
                                        <div className="flex items-center gap-2">
                                            <Input
                                                value={line.phase}
                                                onChange={(e) =>
                                                    setLine(i, {
                                                        phase: e.target.value,
                                                    })
                                                }
                                                placeholder="Phase"
                                                className="w-20"
                                                maxLength={8}
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="text-muted-foreground"
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
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="bg-muted/40 flex flex-wrap items-center gap-4 border-t px-5 py-4 text-sm">
                            <span>
                                Fixed total:{' '}
                                <strong>{pct(String(totals.fixed))}</strong>
                            </span>
                            {totals.qsLines === 1 ? (
                                <span>
                                    QS filler takes{' '}
                                    <strong>
                                        {pct(String(totals.remaining))}
                                    </strong>
                                </span>
                            ) : totals.fixed > 100 ? (
                                <StatusBadge variant="destructive">
                                    Over 100% by{' '}
                                    {pct(String(totals.fixed - 100))}
                                </StatusBadge>
                            ) : totals.complete ? (
                                <StatusBadge variant="success">
                                    Adds up to 100%
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="warning">
                                    {pct(String(totals.remaining))} unassigned —
                                    add a QS line to activate
                                </StatusBadge>
                            )}
                            {totals.qsLines > 1 && (
                                <StatusBadge variant="destructive">
                                    Only one line can be QS
                                </StatusBadge>
                            )}
                            {totals.asRequired > 0 && (
                                <span className="text-muted-foreground">
                                    {totals.asRequired} line
                                    {totals.asRequired === 1 ? '' : 's'} dosed
                                    as required
                                </span>
                            )}
                        </div>
                    </section>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {mode === 'create' ? 'Save as draft' : 'Save draft'}
                        </Button>
                        <p className="text-muted-foreground text-sm">
                            Drafts are not used for production until activated
                            by someone with approval rights.
                        </p>
                    </div>
                </form>
            </div>
        </>
    );
}

FormulaForm.layout = ({
    mode,
    formula,
}: {
    mode: 'create' | 'edit';
    formula: FormulaHead | null;
}) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Formulas', href: index() },
        ...(mode === 'create' || !formula
            ? [{ title: 'New', href: create() }]
            : [
                  { title: formula.code, href: show(formula.id) },
                  { title: 'Edit draft', href: edit(formula.id) },
              ]),
    ],
});
