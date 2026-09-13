import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/status-badge';
import { cn } from '@/lib/utils';

export type DashboardException = {
    key: string;
    rule: string;
    rule_label: string;
    severity: 'high' | 'medium' | 'low';
    title: string;
    detail: string;
    href: string | null;
    age_hours: number;
};

const VARIANT = {
    high: 'destructive',
    medium: 'warning',
    low: 'info',
} as const;

export function ExceptionList({ rows }: { rows: DashboardException[] }) {
    if (rows.length === 0) {
        return (
            <p className="text-muted-foreground px-5 py-6 text-center text-sm">
                All clear.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {rows.map((e) => (
                <li
                    key={e.key}
                    className={cn(
                        'flex items-start justify-between gap-3 px-5 py-3 text-sm',
                    )}
                >
                    <div className="min-w-0">
                        {e.href ? (
                            <Link
                                href={e.href}
                                className="font-medium underline-offset-4 hover:underline"
                            >
                                {e.title}
                            </Link>
                        ) : (
                            <span className="font-medium">{e.title}</span>
                        )}
                        <p className="text-muted-foreground line-clamp-2 text-xs">
                            {e.detail}
                        </p>
                    </div>
                    <StatusBadge
                        variant={VARIANT[e.severity]}
                        className="shrink-0"
                    >
                        {e.rule_label}
                    </StatusBadge>
                </li>
            ))}
        </ul>
    );
}
