import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { TableState } from '@/types';

/**
 * Keeps a list screen's search, sort, filter and paging state in the URL.
 *
 * The server is the authority: it decides what is sortable and what page sizes
 * are allowed, and echoes back the state it actually applied. This hook only
 * pushes changes and reports whether one is in flight.
 *
 * Typing in the search box is debounced so that a search does not fire a
 * request per keystroke.
 */
export function useTableQuery(baseUrl: string, state: TableState) {
    const [search, setSearch] = useState(state.search);
    const [processing, setProcessing] = useState(false);
    const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);

    // A change that came from elsewhere — the back button, a reset — has to be
    // reflected in the input, which otherwise keeps its own stale value.
    useEffect(() => {
        setSearch(state.search);
    }, [state.search]);

    const visit = useCallback(
        (params: Record<string, string | number | undefined>) => {
            const query: Record<string, string> = {};

            const merged = {
                search: state.search,
                sort: state.sort ?? undefined,
                direction: state.direction,
                per_page: state.per_page,
                ...state.filters,
                ...params,
            };

            for (const [key, value] of Object.entries(merged)) {
                if (value !== undefined && value !== null && value !== '') {
                    query[key] = String(value);
                }
            }

            router.get(baseUrl, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            });
        },
        [baseUrl, state],
    );

    const onSearchChange = useCallback(
        (value: string) => {
            setSearch(value);

            if (debounce.current) {
                clearTimeout(debounce.current);
            }

            debounce.current = setTimeout(() => {
                // Any change to the result set invalidates the current page.
                visit({ search: value, page: undefined });
            }, 300);
        },
        [visit],
    );

    const onSort = useCallback(
        (column: string) => {
            const direction =
                state.sort === column && state.direction === 'asc'
                    ? 'desc'
                    : 'asc';

            visit({ sort: column, direction, page: undefined });
        },
        [state.sort, state.direction, visit],
    );

    const onFilter = useCallback(
        (key: string, value: string) => {
            visit({
                [key]: value === 'all' ? undefined : value,
                page: undefined,
            });
        },
        [visit],
    );

    const onPerPage = useCallback(
        (perPage: number) => visit({ per_page: perPage, page: undefined }),
        [visit],
    );

    const onPage = useCallback((page: number) => visit({ page }), [visit]);

    const onReset = useCallback(() => {
        setSearch('');
        router.get(
            baseUrl,
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, [baseUrl]);

    useEffect(
        () => () => {
            if (debounce.current) {
                clearTimeout(debounce.current);
            }
        },
        [],
    );

    return {
        search,
        processing,
        onSearchChange,
        onSort,
        onFilter,
        onPerPage,
        onPage,
        onReset,
    };
}
