import { StatusBadge } from '@/components/status-badge';
import { cn } from '@/lib/utils';

/** RM · PM · FG · QUAR — one chip per store, dimmed when the store is off. */
export function StoreBadges({
    stores,
    max = 6,
}: {
    stores: {
        id?: number;
        badge: string;
        name?: string;
        is_active?: boolean;
    }[];
    max?: number;
}) {
    if (stores.length === 0) {
        return <span className="text-muted-foreground text-sm">No stores</span>;
    }

    const shown = stores.slice(0, max);
    const rest = stores.length - shown.length;

    return (
        <div className="flex flex-wrap items-center gap-1">
            {shown.map((s, i) => (
                <span
                    key={s.id ?? `${s.badge}-${i}`}
                    title={s.name}
                    className={cn(
                        'rounded-md border px-1.5 py-0.5 font-mono text-[11px] font-semibold tracking-wide',
                        s.is_active === false
                            ? 'text-muted-foreground border-dashed opacity-60'
                            : 'bg-primary/10 text-primary border-primary/20',
                    )}
                >
                    {s.badge}
                </span>
            ))}
            {rest > 0 && (
                <span className="text-muted-foreground text-xs">+{rest}</span>
            )}
        </div>
    );
}

/** MFG · QC · STORE… the switches a facility has on. */
export function CapabilityBadges({
    capabilities,
}: {
    capabilities: { key: string; badge: string; label: string }[];
}) {
    if (capabilities.length === 0) {
        return <span className="text-muted-foreground text-sm">None</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {capabilities.map((c) => (
                <StatusBadge
                    key={c.key}
                    variant={c.key === 'can_manufacture' ? 'warning' : 'info'}
                    className="text-[11px]"
                >
                    <span title={c.label}>{c.badge}</span>
                </StatusBadge>
            ))}
        </div>
    );
}

export function money(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return '—';
    return new Intl.NumberFormat('en-IN', {
        style: 'currency',
        currency: 'INR',
        maximumFractionDigits: 0,
    }).format(n);
}
