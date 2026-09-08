import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

type Variant = 'success' | 'warning' | 'destructive' | 'muted' | 'info';

const VARIANTS: Record<Variant, string> = {
    success:
        'border-emerald-600/20 bg-emerald-500/10 text-emerald-700 dark:border-emerald-400/20 dark:text-emerald-300',
    warning:
        'border-amber-600/20 bg-amber-500/10 text-amber-700 dark:border-amber-400/20 dark:text-amber-300',
    destructive:
        'border-red-600/20 bg-red-500/10 text-red-700 dark:border-red-400/20 dark:text-red-300',
    info: 'border-sky-600/20 bg-sky-500/10 text-sky-700 dark:border-sky-400/20 dark:text-sky-300',
    muted: 'border-border bg-muted text-muted-foreground',
};

export function StatusBadge({
    children,
    variant = 'muted',
    className,
}: {
    children: React.ReactNode;
    variant?: Variant;
    className?: string;
}) {
    return (
        <Badge
            variant="outline"
            className={cn('font-medium', VARIANTS[variant], className)}
        >
            {children}
        </Badge>
    );
}

/**
 * The active/inactive badge used across every master-data list.
 */
export function ActiveBadge({ active }: { active: boolean }) {
    return (
        <StatusBadge variant={active ? 'success' : 'muted'}>
            {active ? 'Active' : 'Inactive'}
        </StatusBadge>
    );
}
