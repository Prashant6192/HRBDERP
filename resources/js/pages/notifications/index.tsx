import { Head, Link, router } from '@inertiajs/react';
import { BellOff, CheckCheck } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, read, readAll } from '@/routes/notifications';
import type { ErpNotification, Paginated } from '@/types';

const VARIANT = {
    high: 'destructive',
    medium: 'warning',
    low: 'info',
} as const;

export default function NotificationsIndex({
    notifications,
    unread,
}: {
    notifications: Paginated<ErpNotification>;
    unread: number;
}) {
    const open = (n: ErpNotification) =>
        router.post(
            read(n.id, { query: { open: n.href ? 1 : 0 } }).url,
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Notifications" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Notifications"
                    description={
                        unread > 0
                            ? `${unread} unread.`
                            : 'Everything has been seen.'
                    }
                    actions={
                        unread > 0 ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    router.post(
                                        readAll().url,
                                        {},
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                            >
                                <CheckCheck className="size-4" />
                                Mark all read
                            </Button>
                        ) : undefined
                    }
                />

                <div className="bg-card divide-y rounded-2xl border">
                    {notifications.data.length === 0 ? (
                        <p className="text-muted-foreground p-12 text-center text-sm">
                            <BellOff className="mx-auto mb-2 size-6" />
                            Nothing has needed your attention yet.
                        </p>
                    ) : (
                        notifications.data.map((n) => (
                            <button
                                key={n.id}
                                type="button"
                                onClick={() => open(n)}
                                className={cn(
                                    'hover:bg-muted/40 flex w-full items-start gap-3 px-5 py-4 text-left',
                                    !n.read_at && 'bg-muted/30',
                                )}
                            >
                                <StatusBadge
                                    variant={VARIANT[n.severity] ?? 'warning'}
                                    className="mt-0.5 shrink-0"
                                >
                                    {n.category === 'escalation'
                                        ? 'Escalated'
                                        : n.category}
                                </StatusBadge>
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn(
                                            'block text-sm',
                                            !n.read_at && 'font-semibold',
                                        )}
                                    >
                                        {n.title}
                                    </span>
                                    <span className="text-muted-foreground block text-sm">
                                        {n.body}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {new Date(n.created_at).toLocaleString(
                                            'en-IN',
                                        )}
                                        {n.read_at ? ' · read' : ''}
                                    </span>
                                </span>
                            </button>
                        ))
                    )}
                </div>

                {notifications.last_page > 1 && (
                    <div className="flex flex-wrap justify-center gap-1 text-sm">
                        {notifications.links.map((l, i) =>
                            l.url ? (
                                <Link
                                    key={i}
                                    href={l.url}
                                    className={cn(
                                        'rounded-md border px-3 py-1',
                                        l.active && 'bg-muted font-medium',
                                    )}
                                    dangerouslySetInnerHTML={{
                                        __html: l.label,
                                    }}
                                />
                            ) : null,
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

NotificationsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Notifications', href: index() },
    ],
};
