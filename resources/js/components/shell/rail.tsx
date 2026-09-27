import { Link, usePage } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { PLACE_ICON, type Place } from '@/components/erp-navigation';
import { PLACE_TONE } from '@/components/shell/place-tone';
import { UserMenu } from '@/components/shell/user-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { edit as profileEdit } from '@/routes/profile';
import type { SharedData } from '@/types';

/**
 * The thin strip on the left: one button per place. Choosing a place shows
 * its pages in the column beside it.
 */
export function Rail({
    places,
    current,
    onPick,
}: {
    places: Place[];
    current: Place | null;
    onPick: (key: string) => void;
}) {
    const version = usePage<SharedData>().props.erp?.version;

    return (
        <nav
            aria-label="Places"
            className="bg-muted/40 sticky top-0 hidden h-svh w-[4.5rem] shrink-0 flex-col items-center gap-1.5 border-r py-3 md:flex"
        >
            <Link
                href={dashboard()}
                className="bg-primary mb-3 flex size-9 items-center justify-center rounded-lg"
                title="HRBD ERP"
            >
                <AppLogoIcon className="size-5 fill-current text-white" />
            </Link>

            {places.map((place) => {
                const Icon = PLACE_ICON[place.kind];
                const tone = PLACE_TONE[place.kind];
                const active = current?.key === place.key;

                return (
                    <Tooltip key={place.key}>
                        <TooltipTrigger asChild>
                            <button
                                type="button"
                                aria-pressed={active}
                                aria-label={place.name}
                                onClick={() => onPick(place.key)}
                                className={cn(
                                    'relative flex w-[3.75rem] flex-col items-center gap-1 rounded-xl px-1 py-2 text-[10px] leading-tight font-semibold transition-colors',
                                    active
                                        ? cn(tone.soft, tone.text)
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )}
                            >
                                {active && (
                                    <span
                                        className={cn(
                                            'absolute top-2.5 bottom-2.5 -left-1.5 w-[3px] rounded-full',
                                            tone.bar,
                                        )}
                                    />
                                )}
                                <Icon className="size-5" />
                                <span className="line-clamp-2 w-full text-center [overflow-wrap:normal] [word-break:keep-all]">
                                    {place.short}
                                </span>
                            </button>
                        </TooltipTrigger>
                        <TooltipContent side="right">
                            {place.name}
                        </TooltipContent>
                    </Tooltip>
                );
            })}

            <div className="flex-1" />

            <Tooltip>
                <TooltipTrigger asChild>
                    <Link
                        href={profileEdit()}
                        className="text-muted-foreground hover:bg-muted hover:text-foreground flex size-10 items-center justify-center rounded-xl"
                        aria-label="Settings"
                    >
                        <Settings className="size-5" />
                    </Link>
                </TooltipTrigger>
                <TooltipContent side="right">Your settings</TooltipContent>
            </Tooltip>
            <UserMenu side="right" />
            {version && (
                <span className="text-muted-foreground mt-1 text-[9px]">
                    v{version}
                </span>
            )}
        </nav>
    );
}
