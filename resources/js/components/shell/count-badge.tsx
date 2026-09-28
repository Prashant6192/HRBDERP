import type { NavCount } from '@/hooks/use-places';
import { cn } from '@/lib/utils';

const TONE: Record<NavCount['tone'], string> = {
    bad: 'bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300',
    warn: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
    info: 'bg-sky-100 text-sky-800 dark:bg-sky-950/60 dark:text-sky-300',
};

/** The live number beside a page: what is waiting there now. */
export function CountBadge({
    count,
    className,
}: {
    count?: NavCount;
    className?: string;
}) {
    if (!count || count.n <= 0) return null;

    return (
        <span
            className={cn(
                'ml-auto min-w-6 shrink-0 rounded-full px-1.5 py-0.5 text-center text-[11px] leading-none font-semibold tabular-nums',
                TONE[count.tone],
                className,
            )}
        >
            {count.n > 999 ? '999+' : count.n}
        </span>
    );
}
