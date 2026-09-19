import { Link } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Rupees without decimals for a phone screen; lakhs and crores read easier. */
export function rupees(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);

    if (Math.abs(n) >= 1_00_00_000) {
        return `₹${(n / 1_00_00_000).toFixed(2)} Cr`;
    }

    if (Math.abs(n) >= 1_00_000) {
        return `₹${(n / 1_00_000).toFixed(2)} L`;
    }

    return `₹${n.toLocaleString('en-IN', { maximumFractionDigits: 0 })}`;
}

export function Title({
    children,
    hint,
}: {
    children: ReactNode;
    hint?: string;
}) {
    return (
        <div className="mb-3">
            <h1 className="text-lg font-semibold">{children}</h1>
            {hint && <p className="text-muted-foreground text-xs">{hint}</p>}
        </div>
    );
}

export function Stat({
    label,
    value,
    hint,
    href,
    icon: Icon,
    tone = 'default',
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
    href?: string;
    icon?: LucideIcon;
    tone?: 'default' | 'warn' | 'good' | 'primary';
}) {
    const body = (
        <>
            <div className="text-muted-foreground flex items-center justify-between text-xs">
                <span>{label}</span>
                {Icon && <Icon className="size-4" />}
            </div>
            <div className="mt-1 text-2xl font-semibold tabular-nums">
                {value}
            </div>
            {hint && (
                <div className="text-muted-foreground mt-0.5 text-xs">
                    {hint}
                </div>
            )}
        </>
    );
    const className = cn(
        'block rounded-2xl border p-4',
        tone === 'warn' && 'border-amber-500/50 bg-amber-500/5',
        tone === 'good' && 'border-emerald-500/50 bg-emerald-500/5',
        tone === 'primary' &&
            'bg-primary text-primary-foreground border-primary',
        tone === 'default' && 'bg-card',
        href && 'active:opacity-80',
    );

    return href ? (
        <Link href={href} className={className}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

export function Section({
    title,
    hint,
    children,
    action,
}: {
    title: string;
    hint?: string;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <section className="bg-card mt-4 rounded-2xl border">
            <div className="flex items-center justify-between gap-2 border-b px-4 py-3">
                <div>
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {hint && (
                        <p className="text-muted-foreground text-xs">{hint}</p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

export function Row({
    href,
    title,
    subtitle,
    right,
    rightHint,
    badge,
}: {
    href?: string;
    title: ReactNode;
    subtitle?: ReactNode;
    right?: ReactNode;
    rightHint?: ReactNode;
    badge?: ReactNode;
}) {
    const inner = (
        <>
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                    <span className="truncate text-sm font-medium">
                        {title}
                    </span>
                    {badge}
                </div>
                {subtitle && (
                    <div className="text-muted-foreground text-xs">
                        {subtitle}
                    </div>
                )}
            </div>
            {(right !== undefined || rightHint) && (
                <div className="text-right">
                    <div className="text-sm font-semibold tabular-nums">
                        {right}
                    </div>
                    {rightHint && (
                        <div className="text-muted-foreground text-xs">
                            {rightHint}
                        </div>
                    )}
                </div>
            )}
            {href && (
                <ChevronRight className="text-muted-foreground size-4 shrink-0" />
            )}
        </>
    );
    const className = 'flex items-center gap-3 px-4 py-3';

    return href ? (
        <Link href={href} className={cn(className, 'active:bg-muted')}>
            {inner}
        </Link>
    ) : (
        <div className={className}>{inner}</div>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return (
        <p className="text-muted-foreground px-4 py-6 text-center text-sm">
            {children}
        </p>
    );
}

export function Pill({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'warn' | 'good' | 'bad';
}) {
    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-[11px] font-medium',
                tone === 'muted' && 'bg-muted text-muted-foreground',
                tone === 'warn' && 'bg-amber-500/15 text-amber-700',
                tone === 'good' && 'bg-emerald-500/15 text-emerald-700',
                tone === 'bad' && 'bg-red-500/15 text-red-700',
            )}
        >
            {children}
        </span>
    );
}
