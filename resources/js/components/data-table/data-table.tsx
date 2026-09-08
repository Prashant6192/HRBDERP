import { Link } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    Download,
    RotateCcw,
    Search,
    SlidersHorizontal,
} from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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
import { cn } from '@/lib/utils';
import type { Paginated, TableState } from '@/types';
import { useTableQuery } from './use-table-query';

export type DataTableColumn<T> = {
    /** Matches the server's sortable column name when `sortable` is set. */
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    sortable?: boolean;
    /** Hidden until the reader turns it on in the columns menu. */
    defaultHidden?: boolean;
    /** Kept out of the columns menu — an actions column, typically. */
    alwaysVisible?: boolean;
    headerClassName?: string;
    cellClassName?: string;
};

export type DataTableFilter = {
    key: string;
    label: string;
    options: { value: string; label: string }[];
};

type DataTableProps<T> = {
    columns: DataTableColumn<T>[];
    rows: Paginated<T>;
    state: TableState;
    /** Where list queries are pushed — the module's index URL. */
    baseUrl: string;
    /** Remembers hidden columns per screen, per browser. */
    storageKey: string;
    getRowKey: (row: T) => string | number;
    rowHref?: (row: T) => string;
    filters?: DataTableFilter[];
    searchPlaceholder?: string;
    emptyTitle?: string;
    emptyDescription?: string;
    onExport?: () => void;
    toolbar?: ReactNode;
};

const PER_PAGE_OPTIONS = [10, 25, 50, 100, 200];

export function DataTable<T>({
    columns,
    rows,
    state,
    baseUrl,
    storageKey,
    getRowKey,
    rowHref,
    filters = [],
    searchPlaceholder = 'Search…',
    emptyTitle = 'Nothing to show',
    emptyDescription = 'No records match the current filters.',
    onExport,
    toolbar,
}: DataTableProps<T>) {
    const {
        search,
        processing,
        onSearchChange,
        onSort,
        onFilter,
        onPerPage,
        onPage,
        onReset,
    } = useTableQuery(baseUrl, state);

    const [hidden, setHidden] = useState<string[]>(() =>
        columns.filter((c) => c.defaultHidden).map((c) => c.key),
    );

    // Column choices are a per-person convenience, so they live in the
    // browser. A failure to read them (private mode, blocked storage) must
    // leave the table working, not blank.
    useEffect(() => {
        try {
            const stored = window.localStorage.getItem(
                `datatable:${storageKey}`,
            );

            if (stored) {
                setHidden(JSON.parse(stored) as string[]);
            }
        } catch {
            // Keep the defaults.
        }
    }, [storageKey]);

    const toggleColumn = (key: string) => {
        setHidden((current) => {
            const next = current.includes(key)
                ? current.filter((k) => k !== key)
                : [...current, key];

            try {
                window.localStorage.setItem(
                    `datatable:${storageKey}`,
                    JSON.stringify(next),
                );
            } catch {
                // Not being able to remember the choice is not a reason to
                // refuse to make it.
            }

            return next;
        });
    };

    const visibleColumns = columns.filter(
        (column) => column.alwaysVisible || !hidden.includes(column.key),
    );

    const hasFilters =
        state.search !== '' || Object.keys(state.filters ?? {}).length > 0;

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex flex-1 flex-wrap items-center gap-2">
                    <div className="relative w-full sm:max-w-xs">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                        <Input
                            value={search}
                            onChange={(event) =>
                                onSearchChange(event.target.value)
                            }
                            placeholder={searchPlaceholder}
                            className="pl-8"
                            aria-label={searchPlaceholder}
                        />
                    </div>

                    {filters.map((filter) => (
                        <Select
                            key={filter.key}
                            value={state.filters?.[filter.key] ?? 'all'}
                            onValueChange={(value) =>
                                onFilter(filter.key, value)
                            }
                        >
                            <SelectTrigger className="w-auto min-w-36">
                                <SelectValue placeholder={filter.label} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All {filter.label.toLowerCase()}
                                </SelectItem>
                                {filter.options.map((option) => (
                                    <SelectItem
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ))}

                    {hasFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={onReset}
                            className="text-muted-foreground"
                        >
                            <RotateCcw className="size-4" />
                            Clear
                        </Button>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    {toolbar}

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="outline" size="sm">
                                <SlidersHorizontal className="size-4" />
                                <span className="hidden sm:inline">
                                    Columns
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuLabel>
                                Visible columns
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            {columns
                                .filter((column) => !column.alwaysVisible)
                                .map((column) => (
                                    <DropdownMenuCheckboxItem
                                        key={column.key}
                                        checked={!hidden.includes(column.key)}
                                        onCheckedChange={() =>
                                            toggleColumn(column.key)
                                        }
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                    >
                                        {column.header}
                                    </DropdownMenuCheckboxItem>
                                ))}
                        </DropdownMenuContent>
                    </DropdownMenu>

                    {onExport && (
                        <Button variant="outline" size="sm" onClick={onExport}>
                            <Download className="size-4" />
                            <span className="hidden sm:inline">Export</span>
                        </Button>
                    )}
                </div>
            </div>

            <div
                className={cn(
                    'bg-card rounded-xl border transition-opacity',
                    processing && 'opacity-60',
                )}
            >
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {visibleColumns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className={column.headerClassName}
                                    aria-sort={
                                        state.sort === column.key
                                            ? state.direction === 'asc'
                                                ? 'ascending'
                                                : 'descending'
                                            : undefined
                                    }
                                >
                                    {column.sortable ? (
                                        <button
                                            type="button"
                                            onClick={() => onSort(column.key)}
                                            className="hover:text-foreground -ml-1 inline-flex items-center gap-1 rounded px-1 py-0.5 uppercase transition-colors"
                                        >
                                            {column.header}
                                            {state.sort === column.key ? (
                                                state.direction === 'asc' ? (
                                                    <ArrowUp className="size-3" />
                                                ) : (
                                                    <ArrowDown className="size-3" />
                                                )
                                            ) : (
                                                <ChevronsUpDown className="size-3 opacity-40" />
                                            )}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {rows.data.length === 0 ? (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={visibleColumns.length}
                                    className="py-14 text-center"
                                >
                                    <p className="font-medium">{emptyTitle}</p>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        {emptyDescription}
                                    </p>
                                </TableCell>
                            </TableRow>
                        ) : (
                            rows.data.map((row) => {
                                const href = rowHref?.(row);

                                return (
                                    <TableRow key={getRowKey(row)}>
                                        {visibleColumns.map((column, index) => (
                                            <TableCell
                                                key={column.key}
                                                className={column.cellClassName}
                                            >
                                                {href && index === 0 ? (
                                                    <Link
                                                        href={href}
                                                        className="hover:text-primary font-medium hover:underline"
                                                    >
                                                        {column.cell(row)}
                                                    </Link>
                                                ) : (
                                                    column.cell(row)
                                                )}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                );
                            })
                        )}
                    </TableBody>
                </Table>
            </div>

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-muted-foreground text-sm">
                    {rows.total === 0
                        ? 'No records'
                        : `Showing ${rows.from}–${rows.to} of ${rows.total}`}
                </p>

                <div className="flex items-center gap-2">
                    <Select
                        value={String(state.per_page)}
                        onValueChange={(value) => onPerPage(Number(value))}
                    >
                        <SelectTrigger
                            className="w-auto"
                            aria-label="Rows per page"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PER_PAGE_OPTIONS.map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {option} per page
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={rows.current_page <= 1}
                            onClick={() => onPage(rows.current_page - 1)}
                        >
                            Previous
                        </Button>
                        <span className="text-muted-foreground px-2 text-sm">
                            {rows.current_page} / {Math.max(rows.last_page, 1)}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={rows.current_page >= rows.last_page}
                            onClick={() => onPage(rows.current_page + 1)}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
