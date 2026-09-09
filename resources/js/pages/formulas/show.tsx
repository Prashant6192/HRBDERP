import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CheckCircle2,
    GitBranchPlus,
    Pencil,
    Scale,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog, DeleteDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { FormulaLockChip } from '@/components/formula-lock-chip';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import {
    FORMULA_STATUS_LABEL,
    FORMULA_STATUS_VARIANT,
    pct,
    VERSION_STATUS_LABEL,
    VERSION_STATUS_VARIANT,
} from '@/lib/formulas';
import { date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import {
    archive,
    destroy,
    edit,
    index,
    restore,
    show,
} from '@/routes/formulas';
import versionRoutes from '@/routes/formulas/versions';
import { show as showProduct } from '@/routes/products';
import { show as showRawMaterial } from '@/routes/raw-materials';
import type {
    FormulaIngredientRow,
    FormulaSummary,
    FormulaVersionDetail,
    FormulaVersionSummary,
    ScaledBatch,
    SelectOption,
} from '@/types';

function batchQuantity(
    percentage: string | null,
    batchSize: string,
): string | null {
    if (percentage === null) {
        return null;
    }

    const value = (Number(batchSize) * Number(percentage)) / 100;

    return Number.isFinite(value) ? String(Number(value.toFixed(4))) : null;
}

export default function ShowFormula({
    formula,
    version,
    ingredients,
    versions,
    scaled,
    scaleInput,
    uoms,
    can,
}: {
    formula: FormulaSummary;
    version: FormulaVersionDetail | null;
    ingredients: FormulaIngredientRow[];
    versions: FormulaVersionSummary[];
    scaled: ScaledBatch | null;
    scaleInput: { batch: string; batch_uom: number | string | null };
    uoms: (SelectOption & { dimension: string })[];
    can: {
        update: boolean;
        approve: boolean;
        archive: boolean;
        delete: boolean;
        has_draft: boolean;
        draft_id: number | null;
    };
}) {
    const [batch, setBatch] = useState(String(scaleInput.batch ?? ''));
    const [batchUom, setBatchUom] = useState(
        String(scaleInput.batch_uom ?? version?.batch_uom_id ?? ''),
    );

    const fixedTotal = ingredients.reduce(
        (sum, line) => sum + (line.percentage ? Number(line.percentage) : 0),
        0,
    );
    const qsLine = ingredients.find((l) => l.is_qs);
    const qsShare = qsLine ? 100 - fixedTotal : null;
    const complete = qsLine !== undefined || Math.abs(fixedTotal - 100) < 1e-6;

    const selectVersion = (id: string) =>
        router.get(
            show(formula.id).url,
            { version: id },
            { preserveScroll: true },
        );

    const scale = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            show(formula.id).url,
            { version: version?.id, batch, batch_uom: batchUom },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['scaled', 'scaleInput'],
            },
        );
    };

    const isDraft = version?.status === 'draft';
    const archived = formula.status === 'archived';

    return (
        <>
            <Head title={`${formula.code} · ${formula.name}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={formula.name}
                    description={`${formula.code}${formula.product ? ` · ${formula.product.name}` : ''}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <FormulaLockChip />
                            {can.update && can.has_draft && !archived && (
                                <Button asChild variant="outline" size="sm">
                                    <Link href={edit(formula.id)}>
                                        <Pencil className="size-4" />
                                        Edit draft
                                    </Link>
                                </Button>
                            )}
                            {can.update && !can.has_draft && !archived && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            versionRoutes.store(formula.id).url,
                                        )
                                    }
                                >
                                    <GitBranchPlus className="size-4" />
                                    New version
                                </Button>
                            )}
                            {can.approve && isDraft && version && !archived && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm">
                                            <CheckCircle2 className="size-4" />
                                            Activate v{version.version_number}
                                        </Button>
                                    }
                                    title={`Activate v${version.version_number}?`}
                                    description={
                                        formula.active_version_id
                                            ? 'This becomes the recipe production uses. The current active version is kept as history.'
                                            : 'This becomes the recipe production uses.'
                                    }
                                    confirmLabel="Activate"
                                    action={() =>
                                        router.post(
                                            versionRoutes.activate({
                                                formula: formula.id,
                                                version: version.id,
                                            }).url,
                                        )
                                    }
                                />
                            )}
                            {can.update &&
                                isDraft &&
                                version &&
                                versions.length > 1 && (
                                    <ConfirmDialog
                                        trigger={
                                            <Button variant="ghost" size="sm">
                                                <Trash2 className="size-4" />
                                                Discard draft
                                            </Button>
                                        }
                                        title={`Discard draft v${version.version_number}?`}
                                        description="The draft is deleted. Earlier versions are not affected."
                                        confirmLabel="Discard"
                                        destructive
                                        action={() =>
                                            router.delete(
                                                versionRoutes.destroy({
                                                    formula: formula.id,
                                                    version: version.id,
                                                }).url,
                                            )
                                        }
                                    />
                                )}
                            {can.archive && !archived && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            <Archive className="size-4" />
                                            Archive
                                        </Button>
                                    }
                                    title={`Archive ${formula.name}?`}
                                    description="It stays on record with its history but is no longer offered for production."
                                    confirmLabel="Archive"
                                    action={() =>
                                        router.post(archive(formula.id).url)
                                    }
                                />
                            )}
                            {can.archive && archived && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(restore(formula.id).url)
                                    }
                                >
                                    <ArchiveRestore className="size-4" />
                                    Restore
                                </Button>
                            )}
                            {can.delete && (
                                <DeleteDialog
                                    url={destroy(formula.id).url}
                                    label={`${formula.code} ${formula.name}`}
                                    trigger={
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive"
                                        >
                                            <Trash2 className="size-4" />
                                            Delete
                                        </Button>
                                    }
                                />
                            )}
                        </div>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="bg-card rounded-xl border p-5 lg:col-span-1">
                        <dl className="grid grid-cols-2 gap-4">
                            <DetailItem label="Status">
                                <StatusBadge
                                    variant={
                                        FORMULA_STATUS_VARIANT[formula.status]
                                    }
                                >
                                    {FORMULA_STATUS_LABEL[formula.status]}
                                </StatusBadge>
                            </DetailItem>
                            <DetailItem label="Product">
                                {formula.product ? (
                                    <Link
                                        href={showProduct(formula.product.id)}
                                        className="underline-offset-4 hover:underline"
                                    >
                                        {formula.product.name}
                                    </Link>
                                ) : (
                                    '—'
                                )}
                            </DetailItem>
                            <DetailItem label="Viewing">
                                {version ? (
                                    <Select
                                        value={String(version.id)}
                                        onValueChange={selectVersion}
                                    >
                                        <SelectTrigger className="h-8 w-full">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {versions.map((v) => (
                                                <SelectItem
                                                    key={v.id}
                                                    value={String(v.id)}
                                                >
                                                    v{v.version_number} ·{' '}
                                                    {
                                                        VERSION_STATUS_LABEL[
                                                            v.status
                                                        ]
                                                    }
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                ) : (
                                    '—'
                                )}
                            </DetailItem>
                            <DetailItem label="Reference batch">
                                {version
                                    ? `${qty(version.batch_size)} ${version.batch_uom?.code ?? ''}`
                                    : '—'}
                            </DetailItem>
                            <DetailItem label="Version status">
                                {version ? (
                                    <StatusBadge
                                        variant={
                                            VERSION_STATUS_VARIANT[
                                                version.status
                                            ]
                                        }
                                    >
                                        {VERSION_STATUS_LABEL[version.status]}
                                    </StatusBadge>
                                ) : (
                                    '—'
                                )}
                            </DetailItem>
                            <DetailItem label="Activated">
                                {version?.activated_at
                                    ? date(version.activated_at)
                                    : '—'}
                            </DetailItem>
                            {formula.description && (
                                <div className="col-span-2">
                                    <DetailItem label="Description">
                                        {formula.description}
                                    </DetailItem>
                                </div>
                            )}
                            {version?.notes && (
                                <div className="col-span-2">
                                    <DetailItem label="Process notes">
                                        <span className="whitespace-pre-line">
                                            {version.notes}
                                        </span>
                                    </DetailItem>
                                </div>
                            )}
                        </dl>
                    </section>

                    <section className="bg-card rounded-xl border lg:col-span-2">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="font-semibold">
                                    Recipe
                                    {version
                                        ? ` · v${version.version_number}`
                                        : ''}
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    Quantities shown for the{' '}
                                    {version
                                        ? `${qty(version.batch_size)} ${version.batch_uom?.code ?? ''}`
                                        : ''}{' '}
                                    reference batch.
                                </p>
                            </div>
                            {complete ? (
                                <StatusBadge variant="success">
                                    Accounts for 100%
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="warning">
                                    {pct(String(100 - fixedTotal))} unassigned
                                </StatusBadge>
                            )}
                        </div>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">
                                            #
                                        </TableHead>
                                        <TableHead>Material</TableHead>
                                        <TableHead>INCI</TableHead>
                                        <TableHead>Grade</TableHead>
                                        <TableHead className="text-right">
                                            %
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Qty
                                        </TableHead>
                                        <TableHead>Function</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {ingredients.map((line) => {
                                        const share = line.is_qs
                                            ? qsShare
                                            : line.percentage
                                              ? Number(line.percentage)
                                              : null;
                                        const quantity =
                                            version && share !== null
                                                ? batchQuantity(
                                                      String(share),
                                                      version.batch_size,
                                                  )
                                                : null;

                                        return (
                                            <TableRow key={line.id}>
                                                <TableCell className="text-muted-foreground">
                                                    {line.line_no}
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={showRawMaterial(
                                                            line.item_id,
                                                        )}
                                                        className="font-medium underline-offset-4 hover:underline"
                                                    >
                                                        {line.item_name}
                                                    </Link>
                                                    <div className="text-muted-foreground text-xs">
                                                        {line.item_code}
                                                        {line.phase
                                                            ? ` · Phase ${line.phase}`
                                                            : ''}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {line.inci_name ?? '—'}
                                                </TableCell>
                                                <TableCell>
                                                    {line.grade ?? '—'}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {line.is_qs ? (
                                                        <span title="Quantity sufficient to make up the batch">
                                                            QS (
                                                            {pct(
                                                                String(qsShare),
                                                            )}
                                                            )
                                                        </span>
                                                    ) : line.as_required ? (
                                                        <span className="text-muted-foreground">
                                                            as required
                                                        </span>
                                                    ) : (
                                                        pct(line.percentage)
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {quantity !== null
                                                        ? `${quantity} ${version?.batch_uom?.code ?? ''}`
                                                        : '—'}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {line.purpose ?? '—'}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                        <div className="bg-muted/40 flex flex-wrap gap-4 border-t px-5 py-3 text-sm">
                            <span>
                                Fixed:{' '}
                                <strong>{pct(String(fixedTotal))}</strong>
                            </span>
                            {qsLine && (
                                <span>
                                    QS ({qsLine.item_name}):{' '}
                                    <strong>{pct(String(qsShare))}</strong>
                                </span>
                            )}
                            <span className="text-muted-foreground">
                                {ingredients.length} ingredients
                            </span>
                        </div>
                    </section>
                </div>

                <section className="bg-card rounded-xl border">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                        <div>
                            <h2 className="font-semibold">Scale to a batch</h2>
                            <p className="text-muted-foreground text-sm">
                                Work out how much of each material a production
                                batch needs, in the unit the store holds it.
                            </p>
                        </div>
                        <form
                            onSubmit={scale}
                            className="flex items-center gap-2"
                        >
                            <Input
                                inputMode="decimal"
                                value={batch}
                                onChange={(e) => setBatch(e.target.value)}
                                placeholder="Batch size"
                                className="w-32"
                            />
                            <Select
                                value={batchUom}
                                onValueChange={setBatchUom}
                            >
                                <SelectTrigger className="w-40">
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
                            <Button type="submit" size="sm" variant="outline">
                                <Scale className="size-4" />
                                Calculate
                            </Button>
                        </form>
                    </div>
                    {scaled ? (
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Material</TableHead>
                                        <TableHead className="text-right">
                                            %
                                        </TableHead>
                                        <TableHead className="text-right">
                                            For {qty(scaled.batch_quantity)}{' '}
                                            {scaled.batch_uom}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            In stock unit
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {scaled.lines.map((line) => (
                                        <TableRow key={line.line_no}>
                                            <TableCell>
                                                <span className="font-medium">
                                                    {line.item_name}
                                                </span>
                                                <span className="text-muted-foreground ml-2 text-xs">
                                                    {line.item_code}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.is_qs
                                                    ? `QS (${pct(line.percentage)})`
                                                    : line.as_required
                                                      ? 'as required'
                                                      : pct(line.percentage)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.quantity
                                                    ? `${qty(line.quantity)} ${line.batch_uom}`
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.stock_quantity ? (
                                                    <>
                                                        {qty(
                                                            line.stock_quantity,
                                                        )}{' '}
                                                        {line.stock_uom}
                                                        {line.assumed_density && (
                                                            <span
                                                                className="text-muted-foreground ml-1 text-xs"
                                                                title="No density on the material; 1 g/ml assumed"
                                                            >
                                                                (≈)
                                                            </span>
                                                        )}
                                                    </>
                                                ) : line.quantity ? (
                                                    <span className="text-muted-foreground">
                                                        cannot convert to{' '}
                                                        {line.stock_uom}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            {!scaled.complete && (
                                <p className="text-muted-foreground border-t px-5 py-3 text-sm">
                                    This recipe accounts for{' '}
                                    {pct(scaled.fixed_percentage)} of the batch
                                    and has no QS line; the remainder is not
                                    planned.
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-muted-foreground px-5 py-6 text-sm">
                            Enter a batch size and unit to see the quantities.
                        </p>
                    )}
                </section>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Versions</h2>
                        <p className="text-muted-foreground text-sm">
                            Every recipe this formula has had. Only one is
                            active at a time.
                        </p>
                    </div>
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Version</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Basis</TableHead>
                                    <TableHead>Change</TableHead>
                                    <TableHead>Created</TableHead>
                                    <TableHead>Activated</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {versions.map((v) => (
                                    <TableRow
                                        key={v.id}
                                        className={
                                            v.id === version?.id
                                                ? 'bg-muted/40'
                                                : undefined
                                        }
                                    >
                                        <TableCell>
                                            <Link
                                                href={show(formula.id, {
                                                    query: { version: v.id },
                                                })}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                v{v.version_number}
                                            </Link>
                                            {v.source === 'import' && (
                                                <span className="text-muted-foreground ml-2 text-xs">
                                                    imported
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                variant={
                                                    VERSION_STATUS_VARIANT[
                                                        v.status
                                                    ]
                                                }
                                            >
                                                {VERSION_STATUS_LABEL[v.status]}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell>
                                            {qty(v.batch_size)} {v.batch_uom}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground max-w-xs truncate">
                                            {v.change_summary ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {v.created_at
                                                ? date(v.created_at)
                                                : '—'}
                                            {v.created_by
                                                ? ` · ${v.created_by}`
                                                : ''}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {v.activated_at
                                                ? date(v.activated_at)
                                                : '—'}
                                            {v.approved_by
                                                ? ` · ${v.approved_by}`
                                                : ''}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>
        </>
    );
}

ShowFormula.layout = ({ formula }: { formula: FormulaSummary }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Formulas', href: index() },
        { title: formula.code, href: show(formula.id) },
    ],
});
