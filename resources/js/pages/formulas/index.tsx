import { Head, Link } from '@inertiajs/react';
import { Plus, Upload } from 'lucide-react';
import {
    DataTable,
    type DataTableColumn,
    type DataTableFilter,
} from '@/components/data-table';
import { FormulaLockChip } from '@/components/formula-lock-chip';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { FORMULA_STATUS_LABEL, FORMULA_STATUS_VARIANT } from '@/lib/formulas';
import { date } from '@/lib/stock';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/formulas';
import imports from '@/routes/formulas/imports';
import type {
    FormulaSummary,
    Paginated,
    SelectOption,
    TableState,
} from '@/types';

export default function FormulaIndex({
    formulas,
    table,
    statuses,
    can,
}: {
    formulas: Paginated<FormulaSummary>;
    table: TableState;
    statuses: SelectOption[];
    can: { create: boolean; import: boolean };
}) {
    const columns: DataTableColumn<FormulaSummary>[] = [
        {
            key: 'code',
            header: 'Code',
            sortable: true,
            cell: (f) => f.code,
            cellClassName: 'font-medium whitespace-nowrap',
        },
        {
            key: 'name',
            header: 'Formula',
            sortable: true,
            cell: (f) => f.name,
            cellClassName: 'font-medium',
        },
        {
            key: 'product',
            header: 'Product',
            cell: (f) => f.product?.name ?? '—',
        },
        {
            key: 'status',
            header: 'Status',
            sortable: true,
            cell: (f) => (
                <StatusBadge variant={FORMULA_STATUS_VARIANT[f.status]}>
                    {FORMULA_STATUS_LABEL[f.status]}
                </StatusBadge>
            ),
        },
        {
            key: 'active_version',
            header: 'Active version',
            cell: (f) =>
                f.active_version
                    ? `v${f.active_version.version_number}${
                          f.active_version.activated_at
                              ? ` · ${date(f.active_version.activated_at)}`
                              : ''
                      }`
                    : '—',
            cellClassName: 'whitespace-nowrap',
        },
        {
            key: 'versions_count',
            header: 'Versions',
            cell: (f) => f.versions_count ?? 0,
            cellClassName: 'tabular-nums',
        },
        {
            key: 'updated_at',
            header: 'Updated',
            sortable: true,
            cell: (f) => date(f.updated_at),
            defaultHidden: true,
        },
        {
            key: 'actions',
            header: '',
            alwaysVisible: true,
            headerClassName: 'w-16',
            cell: (f) => (
                <Button variant="ghost" size="sm" asChild>
                    <Link href={show(f.id)}>Open</Link>
                </Button>
            ),
        },
    ];

    const filters: DataTableFilter[] = [
        {
            key: 'status',
            label: 'Status',
            options: statuses.map((s) => ({
                value: String(s.value),
                label: s.label,
            })),
        },
    ];

    return (
        <>
            <Head title="Formulas" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Formulas"
                    description="Every product's recipe, versioned. Opening one asks for your formula PIN; the list itself shows no ingredients."
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <FormulaLockChip />
                            {can.import && (
                                <Button asChild variant="outline">
                                    <Link href={imports.create()}>
                                        <Upload className="size-4" />
                                        Import
                                    </Link>
                                </Button>
                            )}
                            {can.create && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus className="size-4" />
                                        New formula
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />
                <DataTable
                    columns={columns}
                    rows={formulas}
                    state={table}
                    baseUrl={index().url}
                    storageKey="formulas"
                    getRowKey={(f) => f.id}
                    rowHref={(f) => show(f.id).url}
                    filters={filters}
                    searchPlaceholder="Search code, name or product…"
                    emptyTitle="No formulas yet"
                    emptyDescription="Create one, or import the formulation workbook."
                />
            </div>
        </>
    );
}

FormulaIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Formulas', href: index() },
    ],
};
