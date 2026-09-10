import { useCallback, useEffect, useState } from 'react';

export const DASHBOARD_CARDS = [
    { key: 'kpis', label: 'Key figures' },
    { key: 'stores', label: 'Stores at a glance' },
    { key: 'production', label: 'In production' },
    { key: 'receiving', label: 'Receiving & QC' },
    { key: 'output', label: 'Production output' },
    { key: 'attention', label: 'Materials to watch' },
    { key: 'expiring', label: 'Expiring batches' },
    { key: 'upcoming', label: 'Coming up' },
    { key: 'activity', label: 'Recent activity' },
] as const;

export type DashboardCardKey = (typeof DASHBOARD_CARDS)[number]['key'];

const STORAGE_KEY = 'hrbd.dashboard.cards';

/**
 * Which dashboard cards this person wants to see, remembered in the browser.
 *
 * Purely a preference: the server still decides which cards the user may
 * see at all, and sends nothing for the rest.
 */
export function useDashboardCards() {
    const [hidden, setHidden] = useState<DashboardCardKey[]>([]);

    useEffect(() => {
        try {
            const raw = window.localStorage.getItem(STORAGE_KEY);
            if (raw) {
                setHidden(JSON.parse(raw) as DashboardCardKey[]);
            }
        } catch {
            // No storage available — every card shows.
        }
    }, []);

    const toggle = useCallback((key: DashboardCardKey) => {
        setHidden((current) => {
            const next = current.includes(key)
                ? current.filter((k) => k !== key)
                : [...current, key];

            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
            } catch {
                // Preference not persisted; still applied for this visit.
            }

            return next;
        });
    }, []);

    const reset = useCallback(() => {
        setHidden([]);
        try {
            window.localStorage.removeItem(STORAGE_KEY);
        } catch {
            // ignore
        }
    }, []);

    const visible = useCallback(
        (key: DashboardCardKey) => !hidden.includes(key),
        [hidden],
    );

    return { hidden, visible, toggle, reset };
}
