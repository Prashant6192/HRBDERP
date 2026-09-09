import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardPen,
    FileDown,
    RefreshCw,
    XCircle,
} from 'lucide-react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DetailItem } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { RequirementTable } from '@/components/requirement-table';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    PLAN_STATUS_LABEL,
    PLAN_STATUS_VARIANT,
    PMR_STATUS_VARIANT,
} from '@/lib/planning';
import { date, qty } from '@/lib/stock';
import { dashboard } from '@/routes';
import { show as showFormula } from '@/routes/formulas';
import { pdf, show as showRequest } from '@/routes/material-requests';
import { cancel, check, index, requests, show } from '@/routes/plans';
import { show as showProduct } from '@/routes/products';
import type {
    MaterialRequestSummary,
    ProductionPlan,
    RequirementLineRow,
} from '@/types';

function countBy(lines: RequirementLineRow[]) {
    const short = lines.filter((l) => Number(l.shortage) > 0).length;
    const critical = lines.filter(
        (l) => l.level_now === 'critical' || l.level_now === 'out_of_stock',
    ).length;
    const low = lines.filter((l) => l.level_now === 'low').length;
    const moderate = lines.filter((l) => l.level_now === 'moderate').length;

    return { short, critical, low, moderate, total: lines.length };
}

export default function ShowPlan({
    plan,
    rawMaterials,
    packaging,
    requests: pmrs,
    can,
}: {
    plan: ProductionPlan;
    rawMaterials: RequirementLineRow[];
    packaging: RequirementLineRow[];
    requests: MaterialRequestSummary[];
    can: { check: boolean; request: boolean; cancel: boolean };
}) {
    const rm = countBy(rawMaterials);
    const pm = countBy(packaging);
    const anyShort = rm.short + pm.short > 0;

    return (
        <>
            <Head title={plan.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={plan.number}
                    description={`${plan.formula?.name ?? ''} · ${qty(plan.planned_quantity)} ${plan.planned_uom?.code ?? ''}${
                        plan.planned_units
                            ? ` · ${plan.planned_units.toLocaleString()} units`
                            : ''
                    }`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                variant={PLAN_STATUS_VARIANT[plan.status]}
                            >
                                {PLAN_STATUS_LABEL[plan.status]}
                            </StatusBadge>
                            {can.check && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            check(plan.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                >
                                    <RefreshCw className="size-4" />
                                    Re-check stores
                                </Button>
                            )}
                            {can.request && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm">
                                            <ClipboardPen className="size-4" />
                                            Raise material requests
                                        </Button>
                                    }
                                    title="Raise material requests?"
                                    description={`One Production Material Request goes to each store${
                                        packaging.length === 0
                                            ? ' (only the raw material store: no packaging is planned)'
                                            : ' — raw material and packaging'
                                    }. The store picks against it; purchase orders the shortfall.`}
                                    confirmLabel="Raise requests"
                                    action={() =>
                                        router.post(
                                            requests(plan.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="ghost" size="sm">
                                            <XCircle className="size-4" />
                                            Cancel plan
                                        </Button>
                                    }
                                    title={`Cancel ${plan.number}?`}
                                    description="Open material requests raised from it are cancelled too. Stock is not affected."
                                    confirmLabel="Cancel plan"
                                    destructive
                                    action={() =>
                                        router.post(
                                            cancel(plan.id).url,
                                            undefined,
                                            {
                                                preserveScroll: true,
                                            },
                                        )
                                    }
                                />
                            )}
                        </div>
                    }
                />

                {plan.status === 'cancelled' && (
                    <div className="rounded-xl border border-red-500/30 bg-red-500/5 px-5 py-4 text-sm">
                        This plan was cancelled {date(plan.cancelled_at)}.
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="bg-card rounded-xl border p-5">
                        <div className="text-muted-foreground text-sm">
                            Raw materials
                        </div>
                        <div className="mt-1 text-2xl font-semibold">
                            {rm.total}
                            <span className="text-muted-foreground text-base font-normal">
                                {' '}
                                lines
                            </span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {rm.short > 0 ? (
                                <StatusBadge variant="destructive">
                                    {rm.short} short
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="success">
                                    All available
                                </StatusBadge>
                            )}
                            {rm.critical > 0 && (
                                <StatusBadge variant="destructive">
                                    {rm.critical} critically low
                                </StatusBadge>
                            )}
                            {rm.low > 0 && (
                                <StatusBadge variant="warning">
                                    {rm.low} low
                                </StatusBadge>
                            )}
                            {rm.moderate > 0 && (
                                <StatusBadge variant="info">
                                    {rm.moderate} moderate
                                </StatusBadge>
                            )}
                        </div>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <div className="text-muted-foreground text-sm">
                            Packaging
                        </div>
                        <div className="mt-1 text-2xl font-semibold">
                            {pm.total}
                            <span className="text-muted-foreground text-base font-normal">
                                {' '}
                                lines
                            </span>
                        </div>
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {pm.total === 0 ? (
                                <StatusBadge variant="muted">
                                    Not planned
                                </StatusBadge>
                            ) : pm.short > 0 ? (
                                <StatusBadge variant="destructive">
                                    {pm.short} short
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="success">
                                    All available
                                </StatusBadge>
                            )}
                        </div>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <div className="text-muted-foreground text-sm">
                            Recipe
                        </div>
                        <div className="mt-1 text-lg font-semibold">
                            {plan.formula ? (
                                <Link
                                    href={showFormula(plan.formula.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {plan.formula.code}
                                </Link>
                            ) : (
                                '—'
                            )}
                            <span className="text-muted-foreground text-sm font-normal">
                                {' '}
                                v{plan.formula_version?.version_number}
                            </span>
                        </div>
                        <div className="text-muted-foreground mt-1 text-sm">
                            {plan.product ? (
                                <Link
                                    href={showProduct(plan.product.id)}
                                    className="underline-offset-4 hover:underline"
                                >
                                    {plan.product.name}
                                </Link>
                            ) : (
                                'No product linked'
                            )}
                        </div>
                    </div>
                    <div className="bg-card rounded-xl border p-5">
                        <dl className="grid grid-cols-2 gap-3">
                            <DetailItem label="Checked">
                                {date(plan.checked_at)}
                            </DetailItem>
                            <DetailItem label="Start">
                                {date(plan.planned_start_date)}
                            </DetailItem>
                            <DetailItem label="Raised by">
                                {plan.created_by?.name ?? '—'}
                            </DetailItem>
                            <DetailItem label="Requests">
                                {pmrs.length || '—'}
                            </DetailItem>
                        </dl>
                    </div>
                </div>

                {plan.warnings && plan.warnings.length > 0 && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/5 px-5 py-4 text-sm">
                        <div className="mb-1 flex items-center gap-2 font-medium">
                            <AlertTriangle className="size-4" />
                            Things to check
                        </div>
                        <ul className="list-disc space-y-0.5 pl-5">
                            {plan.warnings.map((w, i) => (
                                <li key={i}>{w}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {anyShort && plan.status === 'checked' && (
                    <div className="rounded-xl border border-red-500/30 bg-red-500/5 px-5 py-4 text-sm">
                        <strong>Quantity not available.</strong>{' '}
                        {rm.short + pm.short} material
                        {rm.short + pm.short === 1 ? ' is' : 's are'} short for
                        this batch. Raise the material requests so the store and
                        purchase can act on the &ldquo;short&rdquo; and
                        &ldquo;order to restock&rdquo; columns.
                    </div>
                )}

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Raw Material Store</h2>
                        <p className="text-muted-foreground text-sm">
                            Required for {qty(plan.planned_quantity)}{' '}
                            {plan.planned_uom?.code}, against QC-released,
                            unreserved stock as at {date(plan.checked_at)}.
                        </p>
                    </div>
                    <RequirementTable
                        lines={rawMaterials}
                        emptyText="No raw material lines."
                    />
                </section>

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">
                            Packaging Material Store
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            {plan.planned_units
                                ? `For ${plan.planned_units.toLocaleString()} units of ${plan.product?.name ?? 'product'}.`
                                : 'Packaging is planned from the product’s pack list and net content.'}
                        </p>
                    </div>
                    <RequirementTable
                        lines={packaging}
                        emptyText="No packaging is planned for this batch — see the notes above."
                    />
                </section>

                {pmrs.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <div className="border-b px-5 py-4">
                            <h2 className="font-semibold">Material requests</h2>
                        </div>
                        <ul className="divide-y">
                            {pmrs.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                >
                                    <div>
                                        <Link
                                            href={showRequest(r.id)}
                                            className="font-medium underline-offset-4 hover:underline"
                                        >
                                            {r.number}
                                        </Link>
                                        <span className="text-muted-foreground ml-2 text-sm">
                                            {r.store_label}
                                            {r.warehouse
                                                ? ` · ${r.warehouse}`
                                                : ''}
                                            {r.needed_by
                                                ? ` · needed by ${date(r.needed_by)}`
                                                : ''}
                                        </span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <StatusBadge
                                            variant={
                                                PMR_STATUS_VARIANT[r.status]
                                            }
                                        >
                                            {r.status_label}
                                        </StatusBadge>
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <a
                                                href={pdf(r.id).url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <FileDown className="size-4" />
                                                PDF
                                            </a>
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

ShowPlan.layout = ({ plan }: { plan: ProductionPlan }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Production plans', href: index() },
        { title: plan.number, href: show(plan.id) },
    ],
});
