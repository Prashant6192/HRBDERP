import { Link } from '@inertiajs/react';
import { Search, X } from 'lucide-react';
import { useState } from 'react';
import {
    PLACE_ICON,
    type ErpNavGroup,
    type Place,
} from '@/components/erp-navigation';
import { CountBadge } from '@/components/shell/count-badge';
import { PLACE_TONE } from '@/components/shell/place-tone';
import type { NavCount } from '@/hooks/use-places';
import { cn } from '@/lib/utils';

/**
 * A place's departments and pages, with what is waiting beside each.
 * The small box at the top narrows the list as you type; Ctrl K searches
 * everything else.
 */
export function PageList({
    place,
    groups,
    activeHref,
    counts,
    onNavigate,
    autoFocus = false,
}: {
    place: Place;
    groups: ErpNavGroup[];
    activeHref: string | null;
    counts: Record<string, NavCount>;
    onNavigate?: () => void;
    autoFocus?: boolean;
}) {
    const [filter, setFilter] = useState('');
    const q = filter.trim().toLowerCase();
    const shown = q
        ? groups
              .map((g) => ({
                  ...g,
                  items: g.items.filter((i) =>
                      `${i.title} ${i.keywords ?? ''} ${g.label}`
                          .toLowerCase()
                          .includes(q),
                  ),
              }))
              .filter((g) => g.items.length > 0)
        : groups;

    const tone = PLACE_TONE[place.kind];
    const Icon = PLACE_ICON[place.kind];

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex flex-col gap-2.5 px-3 pt-4 pb-2">
                <h2
                    className={cn(
                        'flex items-center gap-2 text-[15px] leading-tight font-semibold',
                        tone.text,
                    )}
                >
                    <Icon className="size-4 shrink-0" />
                    <span className="truncate">{place.name}</span>
                </h2>
                <label className="bg-background focus-within:ring-ring/40 flex items-center gap-2 rounded-lg border px-2.5 py-1.5 focus-within:ring-2">
                    <Search className="text-muted-foreground size-3.5 shrink-0" />
                    <input
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        placeholder="Find a page…"
                        aria-label={`Find a page in ${place.name}`}
                        autoFocus={autoFocus}
                        className="placeholder:text-muted-foreground min-w-0 flex-1 bg-transparent text-sm outline-none"
                    />
                    {filter && (
                        <button
                            type="button"
                            onClick={() => setFilter('')}
                            aria-label="Clear"
                            className="text-muted-foreground hover:text-foreground"
                        >
                            <X className="size-3.5" />
                        </button>
                    )}
                </label>
            </div>
            <div className="flex-1 overflow-y-auto px-2 pb-4">
                {shown.map((group) => (
                    <div key={group.label} className="mt-2">
                        <p className="text-muted-foreground px-2 pt-2 pb-1 text-[10.5px] font-semibold tracking-[0.08em] uppercase">
                            {group.label}
                        </p>
                        <ul className="flex flex-col gap-px">
                            {group.items.map((item) => {
                                const active = item.href === activeHref;

                                return (
                                    <li key={item.title}>
                                        <Link
                                            href={item.href}
                                            prefetch
                                            onClick={onNavigate}
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                            className={cn(
                                                'flex items-center gap-2.5 rounded-lg px-2 py-[7px] text-sm transition-colors',
                                                active
                                                    ? 'bg-primary/10 text-primary font-semibold'
                                                    : 'text-foreground/85 hover:bg-muted hover:text-foreground',
                                            )}
                                        >
                                            <item.icon
                                                className={cn(
                                                    'size-4 shrink-0',
                                                    active
                                                        ? 'text-primary'
                                                        : 'text-muted-foreground',
                                                )}
                                            />
                                            <span className="truncate">
                                                {item.title}
                                            </span>
                                            <CountBadge
                                                count={
                                                    item.count
                                                        ? counts[item.count]
                                                        : undefined
                                                }
                                            />
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}
                {shown.length === 0 && (
                    <p className="text-muted-foreground px-2 py-6 text-sm">
                        No page here called &ldquo;{filter}&rdquo;. Press Ctrl K
                        to search every place.
                    </p>
                )}
            </div>
        </div>
    );
}

/** The column beside the rail on a desktop or tablet. */
export function PlaceColumn(props: {
    place: Place;
    groups: ErpNavGroup[];
    activeHref: string | null;
    counts: Record<string, NavCount>;
}) {
    return (
        <aside
            aria-label={`${props.place.name} pages`}
            className="bg-card sticky top-0 hidden h-svh w-60 shrink-0 flex-col border-r md:flex"
        >
            <PageList key={props.place.key} {...props} />
        </aside>
    );
}
