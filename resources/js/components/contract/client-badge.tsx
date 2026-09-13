import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/status-badge';
import { show as showClient } from '@/routes/clients';
import { cn } from '@/lib/utils';

/**
 * Whose batch it is, at a glance: OWN BRAND, or THIRD PARTY — ABC WELLNESS.
 * The same pipeline carries both; the badge is what tells them apart.
 */
export function ClientBadge({
    client,
    className,
    link = true,
}: {
    client?: { id: number; name: string; code?: string } | null;
    className?: string;
    link?: boolean;
}) {
    if (!client) {
        return (
            <StatusBadge
                variant="muted"
                className={cn('tracking-wide uppercase', className)}
            >
                Own brand
            </StatusBadge>
        );
    }

    const badge = (
        <StatusBadge
            variant="info"
            className={cn('tracking-wide uppercase', className)}
        >
            Third party — {client.name}
        </StatusBadge>
    );

    return link ? (
        <Link href={showClient(client.id)} className="inline-flex">
            {badge}
        </Link>
    ) : (
        badge
    );
}
