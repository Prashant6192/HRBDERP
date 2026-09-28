import { router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import {
    LABELS_PLACE,
    placeGroups,
    type ErpNavGroup,
    type ErpNavItem,
    type Place,
} from '@/components/erp-navigation';
import { usePermissions } from '@/hooks/use-permissions';
import { counts as navCounts } from '@/routes/nav';

export type NavCount = { n: number; tone: 'bad' | 'warn' | 'info' };

type SharedNav = { places: Place[]; default: string | null } | null;

const STORE_KEY = 'erp.place';

function readStored(): string | null {
    try {
        return window.localStorage.getItem(STORE_KEY);
    } catch {
        return null;
    }
}

function writeStored(key: string): void {
    try {
        window.localStorage.setItem(STORE_KEY, key);
    } catch {
        // A private window: the place is simply not remembered.
    }
}

/**
 * How well a navigation entry describes the page at `url`: 4 is the very
 * page, 3 the same page narrowed further, 2 the same path, 1 a page under
 * it (a record opened from that list), 0.5 the same list filtered
 * differently (another store's stock), 0 unrelated.
 */
export function matchScore(href: string, url: string): number {
    const a = new URL(href, 'http://erp.local');
    const b = new URL(url, 'http://erp.local');

    if (a.pathname === b.pathname) {
        if (a.search === b.search) return 4;

        const wanted = [...a.searchParams.entries()];

        if (wanted.length === 0) return 2;

        return wanted.every(([k, v]) => b.searchParams.get(k) === v) ? 3 : 0.5;
    }

    if (a.pathname !== '/' && b.pathname.startsWith(`${a.pathname}/`)) {
        return 1;
    }

    return 0;
}

/**
 * The places the signed-in person may open, the one they are in now, its
 * departments and pages, and the live numbers beside those pages.
 *
 * The place follows the page: opening a depot's transfer puts you in the
 * depot. Clicking another place on the rail shows its pages without
 * leaving the page you are on, until you open one.
 */
export function usePlaces() {
    const page = usePage<{ nav?: SharedNav }>();
    const url = page.url;
    const { can, isSuperAdmin, isAgency } = usePermissions();

    const places: Place[] = useMemo(() => {
        if (isAgency) return [LABELS_PLACE];

        return page.props.nav?.places ?? [];
    }, [isAgency, page.props.nav]);

    const visible = (item: ErpNavItem): boolean =>
        (item.superAdminOnly ? isSuperAdmin : true) &&
        (!item.permission || can(item.permission));

    const groupsFor = (place: Place): ErpNavGroup[] =>
        placeGroups(place, places)
            .map((g) => ({ ...g, items: g.items.filter(visible) }))
            .filter((g) => g.items.length > 0);

    // Places with nothing the person may open are not offered.
    const usable = places.filter((p) => groupsFor(p).length > 0);

    const [picked, setPicked] = useState<string | null>(null);

    // A new page ends a look at another place's pages.
    useEffect(() => setPicked(null), [url]);

    const resolved = useMemo(() => {
        const stored = typeof window === 'undefined' ? null : readStored();
        const facility = new URL(url, 'http://erp.local').searchParams.get(
            'facility',
        );

        const scored = usable.map((place) => {
            let score = 0;

            for (const group of groupsFor(place)) {
                for (const item of group.items) {
                    score = Math.max(score, matchScore(item.href, url));
                }
            }

            if (facility && String(place.facility_id) === facility) {
                score += 0.5;
            }

            return { place, score };
        });

        const top = Math.max(0, ...scored.map((s) => s.score));
        // The page decides; among equally good places, the one you were in.
        const candidates =
            top > 0
                ? scored.filter((s) => s.score === top).map((s) => s.place)
                : usable;

        return (
            candidates.find((p) => p.key === stored) ??
            candidates.find((p) => p.key === page.props.nav?.default) ??
            candidates[0] ??
            null
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url, usable.map((p) => p.key).join(',')]);

    useEffect(() => {
        if (resolved && picked === null) writeStored(resolved.key);
    }, [resolved, picked]);

    const current =
        (picked ? usable.find((p) => p.key === picked) : null) ??
        resolved ??
        null;

    const groups = current ? groupsFor(current) : [];

    // The one entry that describes this page best, within the place shown.
    let activeHref: string | null = null;
    let activeScore = 0;

    for (const group of groups) {
        for (const item of group.items) {
            const s = matchScore(item.href, url);

            if (s >= 1 && s > activeScore) {
                activeScore = s;
                activeHref = item.href;
            }
        }
    }

    const counts = useNavCounts(isAgency ? null : (current?.key ?? null));

    const pick = (key: string) => {
        setPicked(key);
        writeStored(key);
    };

    return {
        places: usable,
        current,
        groups,
        groupsFor,
        activeHref,
        counts,
        pick,
    };
}

const REFRESH_MS = 60_000;

function useNavCounts(placeKey: string | null): Record<string, NavCount> {
    const [counts, setCounts] = useState<Record<string, NavCount>>({});

    useEffect(() => {
        if (!placeKey) return;

        let cancelled = false;
        const controller = new AbortController();

        const load = () =>
            fetch(navCounts({ query: { place: placeKey } }).url, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((r) => (r.ok ? r.json() : { counts: {} }))
                .then((json: { counts?: Record<string, NavCount> }) => {
                    if (!cancelled) setCounts(json.counts ?? {});
                })
                .catch(() => undefined);

        setCounts({});
        void load();
        const timer = window.setInterval(() => void load(), REFRESH_MS);

        // After anything is saved — a carton counted, a batch released —
        // the numbers catch up at once rather than at the next tick.
        let last = Date.now();
        const stop = router.on('success', () => {
            if (Date.now() - last > 2_000) {
                last = Date.now();
                void load();
            }
        });

        return () => {
            cancelled = true;
            controller.abort();
            window.clearInterval(timer);
            stop();
        };
    }, [placeKey]);

    return counts;
}
