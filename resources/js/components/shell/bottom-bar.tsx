import { Link } from '@inertiajs/react';
import { MoreHorizontal, ScanLine } from 'lucide-react';
import { useState } from 'react';
import {
    PLACE_ICON,
    type ErpNavGroup,
    type Place,
} from '@/components/erp-navigation';
import { PageList } from '@/components/shell/place-column';
import { PLACE_TONE } from '@/components/shell/place-tone';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import type { NavCount } from '@/hooks/use-places';
import { cn } from '@/lib/utils';
import { index as floorIndex } from '@/routes/floor';

/**
 * The phone's navigation: the places along the bottom with Scan in the
 * middle. Tapping a place opens its pages in a sheet from the bottom.
 */
export function BottomBar({
    places,
    current,
    groupsFor,
    activeHref,
    counts,
    onPick,
    showScan,
}: {
    places: Place[];
    current: Place | null;
    groupsFor: (place: Place) => ErpNavGroup[];
    activeHref: string | null;
    counts: Record<string, NavCount>;
    onPick: (key: string) => void;
    showScan: boolean;
}) {
    const [sheet, setSheet] = useState<Place | null>(null);
    const [more, setMore] = useState(false);

    // Four places fit beside Scan; beyond that the last becomes "More".
    const slots = places.length > 4 ? places.slice(0, 3) : places;
    const overflow = places.length > 4 ? places.slice(3) : [];
    const left = slots.slice(0, Math.ceil(slots.length / 2));
    const right = slots.slice(Math.ceil(slots.length / 2));

    const open = (place: Place) => {
        onPick(place.key);
        setSheet(place);
        setMore(false);
    };

    const button = (place: Place) => {
        const Icon = PLACE_ICON[place.kind];
        const active = current?.key === place.key;

        return (
            <button
                key={place.key}
                type="button"
                onClick={() => open(place)}
                aria-pressed={active}
                className={cn(
                    'flex min-w-0 flex-1 flex-col items-center gap-1 rounded-xl px-1 py-1.5 text-[10px] leading-none font-semibold',
                    active
                        ? PLACE_TONE[place.kind].text
                        : 'text-muted-foreground',
                )}
            >
                <Icon className="size-5" />
                <span className="w-full truncate text-center">
                    {place.short}
                </span>
            </button>
        );
    };

    return (
        <>
            <nav
                aria-label="Places on this phone"
                className="bg-card/95 fixed inset-x-0 bottom-0 z-40 flex items-end gap-1 border-t px-2 pt-1.5 pb-[calc(0.5rem+env(safe-area-inset-bottom,0px))] backdrop-blur md:hidden"
            >
                {left.map(button)}
                {showScan && (
                    <Link
                        href={floorIndex()}
                        className="bg-primary text-primary-foreground -mt-4 flex w-16 shrink-0 flex-col items-center gap-1 rounded-2xl px-1 pt-2.5 pb-1.5 text-[10px] leading-none font-semibold shadow-md"
                    >
                        <ScanLine className="size-6" />
                        Scan
                    </Link>
                )}
                {right.map(button)}
                {overflow.length > 0 && (
                    <button
                        type="button"
                        onClick={() => setMore(true)}
                        className="text-muted-foreground flex min-w-0 flex-1 flex-col items-center gap-1 px-1 py-1.5 text-[10px] leading-none font-semibold"
                    >
                        <MoreHorizontal className="size-5" />
                        More
                    </button>
                )}
            </nav>

            <Sheet
                open={sheet !== null}
                onOpenChange={(o) => !o && setSheet(null)}
            >
                <SheetContent
                    side="bottom"
                    className="flex max-h-[82svh] flex-col gap-0 rounded-t-2xl p-0"
                    // Keep the phone's keyboard down until someone types.
                    onOpenAutoFocus={(e) => e.preventDefault()}
                >
                    <SheetTitle className="sr-only">
                        {sheet?.name ?? 'Pages'}
                    </SheetTitle>
                    <SheetDescription className="sr-only">
                        Pages in this place
                    </SheetDescription>
                    <div className="bg-muted mx-auto mt-2.5 h-1 w-10 rounded-full" />
                    {sheet && (
                        <PageList
                            key={sheet.key}
                            place={sheet}
                            groups={groupsFor(sheet)}
                            activeHref={activeHref}
                            counts={current?.key === sheet.key ? counts : {}}
                            onNavigate={() => setSheet(null)}
                        />
                    )}
                </SheetContent>
            </Sheet>

            <Sheet open={more} onOpenChange={setMore}>
                <SheetContent side="bottom" className="rounded-t-2xl p-4">
                    <SheetTitle>More places</SheetTitle>
                    <SheetDescription className="sr-only">
                        Choose a place
                    </SheetDescription>
                    <div className="mt-3 grid grid-cols-2 gap-2">
                        {overflow.map((place) => {
                            const Icon = PLACE_ICON[place.kind];

                            return (
                                <button
                                    key={place.key}
                                    type="button"
                                    onClick={() => open(place)}
                                    className={cn(
                                        'flex items-center gap-2 rounded-xl border p-3 text-left text-sm font-semibold',
                                        PLACE_TONE[place.kind].text,
                                    )}
                                >
                                    <Icon className="size-5" />
                                    {place.name}
                                </button>
                            );
                        })}
                    </div>
                </SheetContent>
            </Sheet>
        </>
    );
}
