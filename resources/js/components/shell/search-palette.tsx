import { router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Boxes,
    CalendarCheck,
    CornerDownLeft,
    Factory,
    FileText,
    FlaskConical,
    Loader2,
    Search,
    ShoppingBag,
    Truck,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ErpNavGroup, Place } from '@/components/erp-navigation';
import { onOpenSearch } from '@/components/shell/search-events';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { search as navSearch } from '@/routes/nav';

type Result = {
    title: string;
    subtitle: string;
    href: string;
    badge?: string | null;
    icon?: LucideIcon;
};

type Group = { group: string; items: Result[] };

const GROUP_ICON: Record<string, LucideIcon> = {
    'Materials and products': FlaskConical,
    Batches: Boxes,
    'Manufacturing orders': Factory,
    'Stock transfers': ArrowLeftRight,
    Dispatches: Truck,
    'Online orders': ShoppingBag,
    'Production plans': CalendarCheck,
    Vendors: Truck,
    People: UserRound,
};

const RECENT_KEY = 'erp.search.recent';

function recent(): Result[] {
    try {
        return JSON.parse(
            window.localStorage.getItem(RECENT_KEY) ?? '[]',
        ) as Result[];
    } catch {
        return [];
    }
}

function remember(result: Result): void {
    try {
        const kept = recent().filter((r) => r.href !== result.href);
        window.localStorage.setItem(
            RECENT_KEY,
            JSON.stringify(
                [{ ...result, icon: undefined }, ...kept].slice(0, 6),
            ),
        );
    } catch {
        // Not remembered in a private window; search still works.
    }
}

/**
 * Ctrl K: go to anything. Pages come from the places the person may open;
 * records (materials, batches, orders, transfers, AWBs…) come from the
 * server, which returns only what they may see.
 */
export function SearchPalette({
    places,
    groupsFor,
}: {
    places: Place[];
    groupsFor: (place: Place) => ErpNavGroup[];
}) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const [records, setRecords] = useState<Group[]>([]);
    const [loading, setLoading] = useState(false);
    const [cursor, setCursor] = useState(0);
    const listRef = useRef<HTMLDivElement>(null);

    useEffect(() => onOpenSearch(() => setOpen(true)), []);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const typing =
                e.target instanceof HTMLElement &&
                (e.target.isContentEditable ||
                    ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName));

            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((o) => !o);
            } else if (e.key === '/' && !typing) {
                e.preventDefault();
                setOpen(true);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (!open) {
            setQ('');
            setRecords([]);
            setCursor(0);
        }
    }, [open]);

    const pages: Result[] = useMemo(() => {
        const seen = new Set<string>();
        const out: Result[] = [];

        for (const place of places) {
            for (const group of groupsFor(place)) {
                for (const item of group.items) {
                    if (seen.has(item.href)) continue;
                    seen.add(item.href);
                    out.push({
                        title: item.title,
                        subtitle: `${place.short} › ${group.label}`,
                        href: item.href,
                        icon: item.icon,
                        badge: `${item.keywords ?? ''} ${group.label} ${place.name}`,
                    });
                }
            }
        }

        return out;
    }, [places, groupsFor]);

    const term = q.trim().toLowerCase();

    const pageHits = term
        ? pages
              .filter((p) =>
                  `${p.title} ${p.badge ?? ''}`.toLowerCase().includes(term),
              )
              .sort(
                  (a, b) =>
                      Number(!a.title.toLowerCase().startsWith(term)) -
                      Number(!b.title.toLowerCase().startsWith(term)),
              )
              .slice(0, 6)
              .map((p) => ({ ...p, badge: null }))
        : [];

    useEffect(() => {
        if (term.length < 2) {
            setRecords([]);
            setLoading(false);

            return;
        }

        const controller = new AbortController();
        setLoading(true);

        const timer = window.setTimeout(() => {
            fetch(navSearch({ query: { q: term } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { groups: [] }))
                .then((json: { groups?: Group[] }) => {
                    setRecords(json.groups ?? []);
                    setLoading(false);
                })
                .catch(() => undefined);
        }, 180);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [term]);

    const shown: Group[] = term
        ? [
              ...(pageHits.length ? [{ group: 'Pages', items: pageHits }] : []),
              ...records,
          ]
        : recent().length
          ? [{ group: 'Recent', items: recent() }]
          : [];

    const flat = shown.flatMap((g) => g.items);

    useEffect(() => setCursor(0), [term, records.length]);

    useEffect(() => {
        listRef.current
            ?.querySelector('[data-active="true"]')
            ?.scrollIntoView({ block: 'nearest' });
    }, [cursor]);

    const go = (result: Result, newTab = false) => {
        remember(result);
        setOpen(false);

        if (newTab) {
            window.open(result.href, '_blank', 'noopener');
        } else {
            router.visit(result.href);
        }
    };

    const onKeyDown = (e: React.KeyboardEvent) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setCursor((c) => Math.min(flat.length - 1, c + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setCursor((c) => Math.max(0, c - 1));
        } else if (e.key === 'Enter' && flat[cursor]) {
            e.preventDefault();
            go(flat[cursor], e.ctrlKey || e.metaKey);
        }
    };

    let index = -1;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent
                className="top-[12%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button]:hidden"
                onKeyDown={onKeyDown}
            >
                <DialogTitle className="sr-only">Search</DialogTitle>
                <div className="flex items-center gap-3 border-b px-4 py-3">
                    <Search className="text-muted-foreground size-5 shrink-0" />
                    <input
                        autoFocus
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search pages, materials, batches, orders, transfers, AWBs…"
                        aria-label="Search"
                        className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-base outline-none"
                    />
                    {loading && (
                        <Loader2 className="text-muted-foreground size-4 animate-spin" />
                    )}
                    <kbd className="text-muted-foreground hidden rounded border px-1.5 py-0.5 text-[10px] sm:inline">
                        Esc
                    </kbd>
                </div>
                <div
                    ref={listRef}
                    className="max-h-[min(60vh,440px)] overflow-y-auto py-1"
                >
                    {shown.map((group) => (
                        <div key={group.group}>
                            <p className="text-muted-foreground px-4 pt-3 pb-1 text-[10.5px] font-semibold tracking-[0.08em] uppercase">
                                {group.group}
                            </p>
                            {group.items.map((item) => {
                                index += 1;
                                const i = index;
                                const Icon =
                                    item.icon ??
                                    GROUP_ICON[group.group] ??
                                    FileText;

                                return (
                                    <button
                                        key={`${group.group}-${item.href}`}
                                        type="button"
                                        data-active={i === cursor}
                                        onMouseMove={() => setCursor(i)}
                                        onClick={(e) =>
                                            go(item, e.ctrlKey || e.metaKey)
                                        }
                                        className={cn(
                                            'flex w-full items-center gap-3 px-4 py-2 text-left',
                                            i === cursor && 'bg-primary/10',
                                        )}
                                    >
                                        <Icon className="text-muted-foreground size-4 shrink-0" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">
                                                {item.title}
                                            </span>
                                            <span className="text-muted-foreground block truncate text-xs">
                                                {item.subtitle}
                                            </span>
                                        </span>
                                        {item.badge && (
                                            <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-[11px] font-medium">
                                                {item.badge}
                                            </span>
                                        )}
                                        {i === cursor && (
                                            <CornerDownLeft className="text-muted-foreground size-3.5" />
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    ))}
                    {term.length >= 2 && !loading && flat.length === 0 && (
                        <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                            Nothing found for &ldquo;{q}&rdquo;. Try a code, a
                            batch number or part of a name.
                        </p>
                    )}
                    {!term && flat.length === 0 && (
                        <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                            Type a page name, a material code like SLES, a
                            batch, a transfer number or an AWB.
                        </p>
                    )}
                </div>
                <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 border-t px-4 py-2 text-[11px]">
                    <span>↑↓ move</span>
                    <span>↵ open</span>
                    <span>Ctrl ↵ new tab</span>
                    <span className="ml-auto">
                        Only what you may open is shown
                    </span>
                </div>
            </DialogContent>
        </Dialog>
    );
}
