import { Link } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationBell } from '@/components/notification-bell';
import { index as floorIndex } from '@/routes/floor';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="border-sidebar-border/50 flex h-16 shrink-0 items-center justify-between gap-2 border-b px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-1">
                <Link
                    href={floorIndex()}
                    className="text-muted-foreground hover:bg-muted hover:text-foreground flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm"
                    title="Shop-floor mode: scan, issue and record on a phone"
                >
                    <ScanLine className="size-4" />
                    <span className="hidden sm:inline">Floor</span>
                </Link>
                <NotificationBell />
            </div>
        </header>
    );
}
