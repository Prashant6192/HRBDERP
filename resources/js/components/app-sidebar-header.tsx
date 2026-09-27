import { Link, usePage } from '@inertiajs/react';
import { ScanLine, Search, Smartphone } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { NotificationBell } from '@/components/notification-bell';
import { openSearch } from '@/components/shell/search-events';
import { UserMenu } from '@/components/shell/user-menu';
import { dashboard } from '@/routes';
import { index as floorIndex } from '@/routes/floor';
import { index as managementIndex } from '@/routes/management';
import type { BreadcrumbItem as BreadcrumbItemType, SharedData } from '@/types';

/**
 * The bar above every page: where you are, the search, and the bell. On a
 * phone it carries the logo and your account too, since the rail is gone.
 */
export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth } = usePage<SharedData>().props;
    const seesReports = auth?.permissions?.includes('report.view') ?? false;
    const isAgency = auth?.agency === true;

    return (
        <header className="bg-background/95 sticky top-0 z-30 flex h-14 shrink-0 items-center gap-2 border-b px-3 backdrop-blur md:h-16 md:px-5">
            <Link
                href={dashboard()}
                className="bg-primary flex size-8 shrink-0 items-center justify-center rounded-lg md:hidden"
                aria-label="Dashboard"
            >
                <AppLogoIcon className="size-4.5 fill-current text-white" />
            </Link>
            <div className="hidden min-w-0 flex-1 md:flex">
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>

            {!isAgency && (
                <button
                    type="button"
                    onClick={openSearch}
                    className="bg-muted/60 text-muted-foreground hover:bg-muted flex h-9 min-w-0 flex-1 items-center gap-2 rounded-lg border px-3 text-sm md:max-w-sm md:flex-none lg:w-80"
                >
                    <Search className="size-4 shrink-0" />
                    <span className="truncate">
                        Go to anything… SLES, a batch, an AWB
                    </span>
                    <kbd className="ml-auto hidden shrink-0 rounded border px-1.5 py-0.5 text-[10px] whitespace-nowrap lg:inline">
                        Ctrl K
                    </kbd>
                </button>
            )}
            {isAgency && <div className="flex-1 md:hidden" />}

            <div className="flex shrink-0 items-center gap-1">
                {seesReports && (
                    <Link
                        href={managementIndex()}
                        className="text-muted-foreground hover:bg-muted hover:text-foreground hidden items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm lg:flex"
                        title="Management view: the factory on a phone"
                    >
                        <Smartphone className="size-4" />
                        <span className="hidden xl:inline">Management</span>
                    </Link>
                )}
                {!isAgency && (
                    <Link
                        href={floorIndex()}
                        className="text-muted-foreground hover:bg-muted hover:text-foreground hidden items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm md:flex"
                        title="Shop-floor mode: scan, issue and record on a phone"
                    >
                        <ScanLine className="size-4" />
                        <span className="hidden xl:inline">Floor</span>
                    </Link>
                )}
                <NotificationBell />
                <div className="md:hidden">
                    <UserMenu />
                </div>
            </div>
        </header>
    );
}
