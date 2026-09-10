import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Boxes,
    CalendarCheck,
    CalendarClock,
    ClipboardCheck,
    ClipboardPen,
    Factory,
    type LucideIcon,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { KpiTile as Tile } from '@/types';

const ICONS: Record<string, LucideIcon> = {
    factory: Factory,
    'calendar-check': CalendarCheck,
    'clipboard-pen': ClipboardPen,
    'clipboard-check': ClipboardCheck,
    'alert-triangle': AlertTriangle,
    'calendar-clock': CalendarClock,
};

const TONE: Record<Tile['tone'], string> = {
    default: 'bg-primary/10 text-primary',
    warning: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    danger: 'bg-red-500/15 text-red-700 dark:text-red-300',
    success: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
};

export function KpiTile({ tile }: { tile: Tile }) {
    const Icon = ICONS[tile.icon] ?? Boxes;

    const body = (
        <div className="bg-card hover:border-primary/40 group flex h-full flex-col rounded-2xl border p-5 transition-colors">
            <div className="flex items-start justify-between gap-3">
                <p className="text-muted-foreground text-sm font-medium">
                    {tile.label}
                </p>
                <span
                    className={cn(
                        'flex size-10 shrink-0 items-center justify-center rounded-xl',
                        TONE[tile.tone],
                    )}
                >
                    <Icon className="size-5" />
                </span>
            </div>
            <p className="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                {tile.value.toLocaleString()}
            </p>
            {tile.hint && (
                <p className="text-muted-foreground mt-1 text-xs">
                    {tile.hint}
                </p>
            )}
        </div>
    );

    return tile.href ? (
        <Link href={tile.href} className="block h-full">
            {body}
        </Link>
    ) : (
        body
    );
}
