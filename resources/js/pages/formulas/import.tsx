import { Head, router, useForm } from '@inertiajs/react';
import { FileSpreadsheet, Upload } from 'lucide-react';
import { useState } from 'react';
import { FormulaLockChip } from '@/components/formula-lock-chip';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { pct } from '@/lib/formulas';
import { dashboard } from '@/routes';
import { index } from '@/routes/formulas';
import imports from '@/routes/formulas/imports';
import type { ImportPlanFormula, PendingImport } from '@/types';

const ACTION_LABEL: Record<ImportPlanFormula['action'], string> = {
    create: 'New formula',
    new_version: 'New version',
    skip_duplicate: 'Skipped · duplicate sheet',
    skip_identical: 'Skipped · already imported',
    skip_draft: 'Skipped · draft open',
};

const ACTION_VARIANT: Record<
    ImportPlanFormula['action'],
    'success' | 'info' | 'muted' | 'warning'
> = {
    create: 'success',
    new_version: 'info',
    skip_duplicate: 'muted',
    skip_identical: 'muted',
    skip_draft: 'warning',
};

export default function ImportFormulas({
    pending,
    can,
}: {
    pending: PendingImport | null;
    can: { activate: boolean };
}) {
    const upload = useForm<{
        workbook: File | null;
        assume_water_qs: boolean;
        activate: boolean;
    }>({
        workbook: null,
        assume_water_qs: true,
        activate: false,
    });

    const [committing, setCommitting] = useState(false);
    const [open, setOpen] = useState<Record<string, boolean>>({});

    const preview = (e: React.FormEvent) => {
        e.preventDefault();
        upload.post(imports.preview().url, { forceFormData: true });
    };

    const commit = () => {
        if (!pending) {
            return;
        }

        setCommitting(true);
        router.post(
            imports.store().url,
            {
                token: pending.token,
                assume_water_qs: pending.options.assume_water_qs,
                activate: pending.options.activate,
            },
            { onFinish: () => setCommitting(false) },
        );
    };

    const actionable = pending
        ? pending.plan.summary.create + pending.plan.summary.new_version
        : 0;

    return (
        <>
            <Head title="Import formulations" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Import formulations"
                    description="Upload the formulation workbook — one product per sheet. You will see exactly what would be created before anything is saved."
                    actions={<FormulaLockChip />}
                />

                {!pending && (
                    <form
                        onSubmit={preview}
                        className="bg-card max-w-2xl space-y-5 rounded-xl border p-5"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="workbook">
                                Excel workbook (.xlsx)
                            </Label>
                            <Input
                                id="workbook"
                                type="file"
                                accept=".xlsx,.xls"
                                onChange={(e) =>
                                    upload.setData(
                                        'workbook',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                                required
                            />
                            <InputError message={upload.errors.workbook} />
                            <p className="text-muted-foreground text-sm">
                                Each sheet holds one product: its name at the
                                top, then the ingredients with their grade,
                                percentage (or &ldquo;QS to 100&rdquo;) and
                                function. Both the column layout and the
                                one-cell-per-line layout are understood.
                            </p>
                        </div>

                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={upload.data.assume_water_qs}
                                onCheckedChange={(v) =>
                                    upload.setData(
                                        'assume_water_qs',
                                        v === true,
                                    )
                                }
                                className="mt-0.5"
                            />
                            <span>
                                <span className="font-medium">
                                    Assume water makes up the rest
                                </span>
                                <span className="text-muted-foreground block">
                                    Where a sheet lists no filler, add
                                    &ldquo;Purified Water — QS to 100&rdquo; so
                                    the recipe can be activated and planned.
                                </span>
                            </span>
                        </label>

                        {can.activate && (
                            <label className="flex items-start gap-3 text-sm">
                                <Checkbox
                                    checked={upload.data.activate}
                                    onCheckedChange={(v) =>
                                        upload.setData('activate', v === true)
                                    }
                                    className="mt-0.5"
                                />
                                <span>
                                    <span className="font-medium">
                                        Activate imported recipes
                                    </span>
                                    <span className="text-muted-foreground block">
                                        Otherwise they are saved as drafts for
                                        review and activated one by one.
                                    </span>
                                </span>
                            </label>
                        )}

                        <Button type="submit" disabled={upload.processing}>
                            <Upload className="size-4" />
                            {upload.processing ? 'Reading…' : 'Preview import'}
                        </Button>
                    </form>
                )}

                {pending && (
                    <>
                        <div className="bg-card flex flex-wrap items-center justify-between gap-4 rounded-xl border p-5">
                            <div className="flex items-center gap-3">
                                <FileSpreadsheet className="text-muted-foreground size-8" />
                                <div>
                                    <div className="font-medium">
                                        {pending.file_name}
                                    </div>
                                    <div className="text-muted-foreground text-sm">
                                        {pending.plan.summary.create} to create
                                        · {pending.plan.summary.new_version} new
                                        version
                                        {pending.plan.summary.new_version === 1
                                            ? ''
                                            : 's'}{' '}
                                        · {pending.plan.summary.skip} skipped ·{' '}
                                        {
                                            pending.plan.summary
                                                .materials_to_create
                                        }{' '}
                                        raw material
                                        {pending.plan.summary
                                            .materials_to_create === 1
                                            ? ''
                                            : 's'}{' '}
                                        to add
                                        {pending.options.activate
                                            ? ' · will activate'
                                            : ' · saved as drafts'}
                                    </div>
                                </div>
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(imports.destroy().url)
                                    }
                                    disabled={committing}
                                >
                                    Discard
                                </Button>
                                <Button
                                    onClick={commit}
                                    disabled={committing || actionable === 0}
                                >
                                    {committing
                                        ? 'Importing…'
                                        : `Import ${actionable} formula${actionable === 1 ? '' : 's'}`}
                                </Button>
                            </div>
                        </div>

                        {pending.plan.skipped_sheets.length > 0 && (
                            <p className="text-muted-foreground text-sm">
                                Empty sheets ignored:{' '}
                                {pending.plan.skipped_sheets.join(', ')}
                            </p>
                        )}

                        <div className="space-y-4">
                            {pending.plan.formulas.map((f) => {
                                const key = `${f.sheet}`;
                                const expanded =
                                    open[key] ?? f.action !== 'skip_duplicate';
                                const newMaterials = f.lines.filter(
                                    (l) => l.action === 'create',
                                ).length;

                                return (
                                    <section
                                        key={key}
                                        className="bg-card rounded-xl border"
                                    >
                                        <button
                                            type="button"
                                            className="flex w-full flex-wrap items-center justify-between gap-3 px-5 py-4 text-left"
                                            onClick={() =>
                                                setOpen({
                                                    ...open,
                                                    [key]: !expanded,
                                                })
                                            }
                                        >
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h2 className="font-semibold">
                                                        {f.name}
                                                    </h2>
                                                    <StatusBadge
                                                        variant={
                                                            ACTION_VARIANT[
                                                                f.action
                                                            ]
                                                        }
                                                    >
                                                        {ACTION_LABEL[f.action]}
                                                        {f.existing_code
                                                            ? ` · ${f.existing_code}`
                                                            : ''}
                                                    </StatusBadge>
                                                </div>
                                                <p className="text-muted-foreground text-sm">
                                                    Sheet &ldquo;{f.sheet}
                                                    &rdquo; · basis{' '}
                                                    {f.batch_size} {f.batch_uom}{' '}
                                                    · {f.lines.length}{' '}
                                                    ingredients ·{' '}
                                                    {pct(f.total_percentage)}{' '}
                                                    fixed
                                                    {f.has_qs
                                                        ? ' + QS'
                                                        : ' (no QS)'}
                                                    {newMaterials > 0
                                                        ? ` · ${newMaterials} new material${newMaterials === 1 ? '' : 's'}`
                                                        : ''}
                                                    {f.product_name
                                                        ? ` · product: ${f.product_name}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <span className="text-muted-foreground text-sm">
                                                {expanded ? 'Hide' : 'Show'}
                                            </span>
                                        </button>

                                        {expanded && (
                                            <>
                                                {f.warnings.length > 0 && (
                                                    <ul className="border-t px-5 py-3 text-sm">
                                                        {f.warnings.map(
                                                            (w, i) => (
                                                                <li
                                                                    key={i}
                                                                    className="text-amber-700 dark:text-amber-300"
                                                                >
                                                                    {w}
                                                                </li>
                                                            ),
                                                        )}
                                                    </ul>
                                                )}
                                                <div className="overflow-x-auto border-t">
                                                    <Table>
                                                        <TableHeader>
                                                            <TableRow>
                                                                <TableHead className="w-10">
                                                                    #
                                                                </TableHead>
                                                                <TableHead>
                                                                    Ingredient
                                                                </TableHead>
                                                                <TableHead>
                                                                    Grade
                                                                </TableHead>
                                                                <TableHead className="text-right">
                                                                    %
                                                                </TableHead>
                                                                <TableHead>
                                                                    Function
                                                                </TableHead>
                                                                <TableHead>
                                                                    Master data
                                                                </TableHead>
                                                            </TableRow>
                                                        </TableHeader>
                                                        <TableBody>
                                                            {f.lines.map(
                                                                (l) => (
                                                                    <TableRow
                                                                        key={
                                                                            l.line_no
                                                                        }
                                                                    >
                                                                        <TableCell className="text-muted-foreground">
                                                                            {
                                                                                l.line_no
                                                                            }
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            <div className="font-medium">
                                                                                {
                                                                                    l.name
                                                                                }
                                                                            </div>
                                                                            {l.trade_name && (
                                                                                <div className="text-muted-foreground text-xs">
                                                                                    Trade
                                                                                    name:{' '}
                                                                                    {
                                                                                        l.trade_name
                                                                                    }
                                                                                </div>
                                                                            )}
                                                                            {l.warnings.map(
                                                                                (
                                                                                    w,
                                                                                    i,
                                                                                ) => (
                                                                                    <div
                                                                                        key={
                                                                                            i
                                                                                        }
                                                                                        className="text-xs text-amber-700 dark:text-amber-300"
                                                                                    >
                                                                                        {
                                                                                            w
                                                                                        }
                                                                                    </div>
                                                                                ),
                                                                            )}
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            {l.grade ??
                                                                                '—'}
                                                                        </TableCell>
                                                                        <TableCell className="text-right tabular-nums">
                                                                            {l.is_qs
                                                                                ? 'QS'
                                                                                : l.as_required
                                                                                  ? 'as required'
                                                                                  : pct(
                                                                                        l.percentage,
                                                                                    )}
                                                                        </TableCell>
                                                                        <TableCell className="text-muted-foreground">
                                                                            {l.purpose ??
                                                                                '—'}
                                                                        </TableCell>
                                                                        <TableCell>
                                                                            {l.action ===
                                                                            'match' ? (
                                                                                <span className="text-muted-foreground">
                                                                                    {
                                                                                        l.item_code
                                                                                    }
                                                                                </span>
                                                                            ) : (
                                                                                <StatusBadge variant="info">
                                                                                    New
                                                                                    material
                                                                                </StatusBadge>
                                                                            )}
                                                                        </TableCell>
                                                                    </TableRow>
                                                                ),
                                                            )}
                                                        </TableBody>
                                                    </Table>
                                                </div>
                                            </>
                                        )}
                                    </section>
                                );
                            })}
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

ImportFormulas.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Formulas', href: index() },
        { title: 'Import', href: imports.create() },
    ],
};
