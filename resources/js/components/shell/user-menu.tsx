import { usePage } from '@inertiajs/react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import type { SharedData } from '@/types';

/** Your account: settings and sign out, from the rail or the phone header. */
export function UserMenu({ side = 'bottom' }: { side?: 'right' | 'bottom' }) {
    const { auth } = usePage<SharedData>().props;
    const getInitials = useInitials();

    if (!auth.user) return null;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Your account"
                    className="bg-primary/15 text-primary hover:bg-primary/25 flex size-9 items-center justify-center rounded-full text-xs font-bold"
                    data-test="sidebar-menu-button"
                >
                    {getInitials(auth.user.name)}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                className="min-w-56 rounded-lg"
                side={side}
                align="end"
            >
                <UserMenuContent user={auth.user} />
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
