import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
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
import { ALERT_VARIANT, qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index } from '@/routes/stock';
import { show as showRawMaterial } from '@/routes/raw-materials';
import { show as showPackaging } from '@/routes/packaging-materials';
import { show as showProduct } from '@/routes/products';
import type { StockAlertLevel, StockRow } from '@/types';

type WarehouseOption = {
    id: number;
    code: string;
    name: string;
    type: string;
    is_quarantine: boolean;
    facility_id: number | null;
    facility: string | null;
};

type FacilityOption = { id: number; code: string; name: string };

type LevelDef = {
    value: StockAlertLevel;
    label: string;
    variant: string;
    severity: number;
};

function itemHref(row: StockRow): string | null {
    switch (row.type) {
        case 'raw_material':
            return showRawMaterial(row.item_id).url;
        case 'packaging_material':
            return showPackaging(row.item_id).url;
        case 'finished_good':
            return showProduct(row.item_id).url;
        default:
            return null;
    }
}

export default function StockIndex({
    facilities,
    facility,
    warehouses,
    selected,
    rows,
    counts,
    levels,
    filters,
    expiring,
}: {
    facilities: FacilityOption[];
    facility: number | null;
    warehouses: WarehouseOption[];
    selected: WarehouseOption | null;
    rows: StockRow[];
    counts: Record<StockAlertLevel, number>;
    levels: LevelDef[];
    filters: { search: string; level: string };
    expiring: { days: number; count: number };
}) {
    const [search, setSearch] = useState(filters.search);
    const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => setSearch(filters.search), [filters.search]);

    const visit = (params: Record<string, string | number | undefined>) => {
        router.get(
            index().url,
            {
                facility: facility ?? undefined,
                warehouse: selected?.id,
                search: filters.search || undefined,
                level: filters.level !== 'all' ? filters.level : undefined,
                ...params,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const attention = levels
        .filter((l) => l.severity > 0)
        .sort((a, b) => b.severity - a.severity);

    return (
        <>
            <Head title="Stock" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Stock"
                    description="What is on the shelf, what is held for production, and what needs ordering."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {facilities.length > 1 && (
                                <Select
                                    value={facility ? String(facility) : ''}
                                    onValueChange={(v) =>
                                        visit({
                                            facility: v,
                                            warehouse: undefined,
                                            level: undefined,
                                        })
                                    }
                                >
                                    <SelectTrigger className="min-w-48">
                                        <SelectValue placeholder="Facility" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {facilities.map((f) => (
                                            <SelectItem
                                                key={f.id}
                                                value={String(f.id)}
                                            >
                                                {f.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            )}
                            <Select
                                value={selected ? String(selected.id) : ''}
                                onValueChange={(v) =>
                                    visit({ warehouse: v, level: undefined })
                                }
                            >
                                <SelectTrigger className="min-w-56">
                                    <SelectValue placeholder="Choose a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    {warehouses
                                        .filter(
                                            (w) =>
                                                facility === null ||
                                                w.facility_id === facility,
                                        )
                                        .map((w) => (
                                            <SelectItem
                                                key={w.id}
                                                value={String(w.id)}
                                            >
                                                {w.code} — {w.name}
                                                {w.is_quarantine
                                                    ? ' (quarantine)'
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    {attention.map((l) => (
                        <button
                            key={l.value}
                            type="button"
                            onClick={() =>
                                visit({
                                    level:
                                        filters.level === l.value
                                            ? undefined
                                            : l.value,
                                })
                            }
                            className={cn(
                                'bg-card rounded-xl border p-5 text-left transition-colors',
                                filters.level === l.value
                                    ? 'border-primary'
                                    : 'hover:border-primary/40',
                            )}
                        >
                            <div className="flex items-center justify-between">
                                <span className="text-muted-foreground text-sm">
                                    {l.label}
                                </span>
                                <AlertTriangle
                                    className={cn(
                                        'size-4',
                                        l.severity >= 3
                                            ? 'text-red-600'
                                            : l.severity === 2
                                              ? 'text-amber-600'
                                              : 'text-sky-600',
                                    )}
                                />
                            </div>
                            <p className="mt-2 text-3xl font-semibold tabular-nums">
                                {counts[l.value] ?? 0}
                            </p>
                        </button>
                    ))}
                    <div className="bg-card rounded-xl border p-5">
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground text-sm">
                                Expiring ≤ {expiring.days} days
                            </span>
                            <CalendarClock className="text-muted-foreground size-4" />
                        </div>
                        <p className="mt-2 text-3xl font-semibold tabular-nums">
                            {expiring.count}
                        </p>
                    </div>
                </div>

                <div className="relative w-full sm:max-w-xs">
                    <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                    <Input
                        value={search}
                        placeholder="Search item…"
                        className="pl-8"
                        onChange={(e) => {
                            setSearch(e.target.value);
                            if (debounce.current)
                                clearTimeout(debounce.current);
                            debounce.current = setTimeout(
                                () =>
                                    visit({
                                        search: e.target.value || undefined,
                                    }),
                                300,
                            );
                        }}
                    />
                </div>

                <div className="bg-card rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Item</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    Reserved
                                </TableHead>
                                <TableHead className="text-right">
                                    Available
                                </TableHead>
                                <TableHead className="text-right">
                                    Reorder at
                                </TableHead>
                                <TableHead>Alert</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 ? (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={6}
                                        className="py-14 text-center"
                                    >
                                        <p className="font-medium">
                                            Nothing in this store
                                        </p>
                                        <p className="text-muted-foreground mt-1 text-sm">
                                            Stock appears here once a goods
                                            receipt is posted and released.
                                        </p>
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.map((row) => {
                                    const href = itemHref(row);
                                    return (
                                        <TableRow key={row.item_id}>
                                            <TableCell>
                                                {href ? (
                                                    <Link
                                                        href={href}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {row.code}
                                                    </Link>
                                                ) : (
                                                    <span className="font-medium">
                                                        {row.code}
                                                    </span>
                                                )}
                                                <div className="text-muted-foreground text-xs">
                                                    {row.name}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {qty(
                                                    row.on_hand,
                                                    row.display_scale,
                                                )}{' '}
                                                {row.uom}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-right tabular-nums">
                                                {qty(
                                                    row.reserved,
                                                    row.display_scale,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right font-medium tabular-nums">
                                                {qty(
                                                    row.available,
                                                    row.display_scale,
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground text-right tabular-nums">
                                                {row.reorder_level
                                                    ? qty(
                                                          row.reorder_level,
                                                          row.display_scale,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    variant={
                                                        ALERT_VARIANT[row.level]
                                                    }
                                                >
                                                    {row.level_label}
                                                </StatusBadge>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

StockIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stock', href: index() },
    ],
};
