import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { BottomBar } from '@/components/shell/bottom-bar';
import { PlaceColumn } from '@/components/shell/place-column';
import { Rail } from '@/components/shell/rail';
import { SearchPalette } from '@/components/shell/search-palette';
import { usePermissions } from '@/hooks/use-permissions';
import { usePlaces } from '@/hooks/use-places';
import type { AppLayoutProps } from '@/types';

/**
 * The Rail + Search shell.
 *
 * Desktop and tablet: a rail of places, the chosen place's pages in a
 * column, and the page. Phone: the page, with the places along the bottom
 * and Scan in the middle. Ctrl K searches everything from anywhere.
 */
export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    const { places, current, groups, groupsFor, activeHref, counts, pick } =
        usePlaces();
    const { isAgency } = usePermissions();

    return (
        <div className="bg-background flex min-h-svh w-full">
            <Rail places={places} current={current} onPick={pick} />
            {current && (
                <PlaceColumn
                    place={current}
                    groups={groups}
                    activeHref={activeHref}
                    counts={counts}
                />
            )}
            <div className="flex min-w-0 flex-1 flex-col pb-[calc(4.5rem+env(safe-area-inset-bottom,0px))] md:pb-0">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <main className="flex min-w-0 flex-1 flex-col overflow-x-clip">
                    {children}
                </main>
            </div>
            <BottomBar
                places={places}
                current={current}
                groupsFor={groupsFor}
                activeHref={activeHref}
                counts={counts}
                onPick={pick}
                showScan={!isAgency}
            />
            {!isAgency && (
                <SearchPalette places={places} groupsFor={groupsFor} />
            )}
        </div>
    );
}
