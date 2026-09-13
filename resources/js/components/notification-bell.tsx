import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { index, read, readAll } from '@/routes/notifications';
import type { SharedData } from '@/types';

const DOT: Record<string, string> = {
    high: 'bg-red-500',
    medium: 'bg-amber-500',
    low: 'bg-sky-500',
};

function ago(iso: string): string {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );
    if (minutes < 1) return 'just now';
    if (minutes < 60) return `${minutes} min ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} h ago`;
    return `${Math.round(hours / 24)} d ago`;
}

/**
 * The bell: unread count and the latest few, each opening what it is about
 * and marking itself read on the way.
 */
export function NotificationBell() {
    const { notifications } = usePage<SharedData>().props;

    if (!notifications) {
        return null;
    }

    const open = (id: string, href: string | null) => {
        router.post(
            read(id, { query: { open: href ? 1 : 0 } }).url,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative"
                    aria-label={
                        notifications.unread > 0
                            ? `${notifications.unread} unread notifications`
                            : 'Notifications'
                    }
                    data-testid="notification-bell"
                >
                    <Bell className="size-5" />
                    {notifications.unread > 0 && (
                        <span className="bg-primary text-primary-foreground absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold tabular-nums">
                            {notifications.unread > 99
                                ? '99+'
                                : notifications.unread}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-96 max-w-[92vw]">
                <DropdownMenuLabel className="flex items-center justify-between">
                    <span>Notifications</span>
                    {notifications.unread > 0 && (
                        <button
                            type="button"
                            className="text-muted-foreground inline-flex items-center gap-1 text-xs font-normal hover:underline"
                            onClick={() =>
                                router.post(
                                    readAll().url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <CheckCheck className="size-3.5" />
                            Mark all read
                        </button>
                    )}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {notifications.latest.length === 0 ? (
                    <p className="text-muted-foreground px-2 py-6 text-center text-sm">
                        Nothing needs your attention.
                    </p>
                ) : (
                    notifications.latest.map((n) => (
                        <DropdownMenuItem
                            key={n.id}
                            className={cn(
                                'flex cursor-pointer items-start gap-2 py-2',
                                !n.read_at && 'bg-muted/40',
                            )}
                            onSelect={() => open(n.id, n.href)}
                        >
                            <span
                                className={cn(
                                    'mt-1.5 size-2 shrink-0 rounded-full',
                                    DOT[n.severity] ?? DOT.medium,
                                )}
                            />
                            <span className="min-w-0 flex-1">
                                <span
                                    className={cn(
                                        'block truncate text-sm',
                                        !n.read_at && 'font-medium',
                                    )}
                                >
                                    {n.title}
                                </span>
                                <span className="text-muted-foreground line-clamp-2 block text-xs">
                                    {n.body}
                                </span>
                                <span className="text-muted-foreground block text-[11px]">
                                    {ago(n.created_at)}
                                </span>
                            </span>
                        </DropdownMenuItem>
                    ))
                )}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        href={index()}
                        className="w-full cursor-pointer justify-center text-sm"
                    >
                        All notifications
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
