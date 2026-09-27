import type { PlaceKind } from '@/components/erp-navigation';

/**
 * Each kind of place keeps one colour everywhere it appears: the rail, the
 * column heading, the phone's bottom bar. Blue makes, green ships, purple
 * is contract work, slate is the back office.
 */
export const PLACE_TONE: Record<
    PlaceKind,
    { text: string; soft: string; bar: string }
> = {
    factory: {
        text: 'text-blue-700 dark:text-blue-300',
        soft: 'bg-blue-50 dark:bg-blue-950/50',
        bar: 'bg-blue-600 dark:bg-blue-400',
    },
    depot: {
        text: 'text-emerald-700 dark:text-emerald-300',
        soft: 'bg-emerald-50 dark:bg-emerald-950/50',
        bar: 'bg-emerald-600 dark:bg-emerald-400',
    },
    contract: {
        text: 'text-violet-700 dark:text-violet-300',
        soft: 'bg-violet-50 dark:bg-violet-950/50',
        bar: 'bg-violet-600 dark:bg-violet-400',
    },
    company: {
        text: 'text-slate-700 dark:text-slate-300',
        soft: 'bg-slate-100 dark:bg-slate-800/60',
        bar: 'bg-slate-600 dark:bg-slate-400',
    },
    labels: {
        text: 'text-orange-700 dark:text-orange-300',
        soft: 'bg-orange-50 dark:bg-orange-950/50',
        bar: 'bg-orange-600 dark:bg-orange-400',
    },
};
