import { Link, usePage } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { usePermissions } from '@/hooks/use-permissions';
import { erpNavigation, type ErpNavItem } from '@/components/erp-navigation';

/**
 * The main sidebar navigation.
 *
 * Groups with nothing the user may reach are dropped entirely, rather than
 * left as an empty heading. Entries still on the roadmap render disabled,
 * so the shape of the system is visible before every part of it exists.
 */
export function NavMain() {
    const { isCurrentUrl } = useCurrentUrl();
    const { can } = usePermissions();
    const { url } = usePage();

    const groups = erpNavigation
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => !item.permission || can(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);

    const active = (item: ErpNavItem): boolean => {
        if (item.comingSoon) {
            return false;
        }

        if (item.exact) {
            return url === item.href;
        }

        // A path that other entries refine with a query string is only
        // current when none of those refinements is.
        const refined = erpNavigation.some((g) =>
            g.items.some(
                (other) =>
                    other.exact &&
                    other.href.split('?')[0] === item.href &&
                    url === other.href,
            ),
        );

        return !refined && isCurrentUrl(item.href);
    };

    return (
        <>
            {groups.map((group) => (
                <SidebarGroup key={group.label} className="px-2 py-0">
                    <SidebarGroupLabel>{group.label}</SidebarGroupLabel>
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                {item.comingSoon ? (
                                    <SidebarMenuButton
                                        disabled
                                        tooltip={{
                                            children: `${item.title} — coming soon`,
                                        }}
                                        className="opacity-60"
                                    >
                                        <item.icon />
                                        <span>{item.title}</span>
                                        <span className="bg-muted text-muted-foreground ml-auto rounded px-1.5 py-0.5 text-[10px] font-medium uppercase">
                                            Soon
                                        </span>
                                    </SidebarMenuButton>
                                ) : (
                                    <SidebarMenuButton
                                        asChild
                                        isActive={active(item)}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={item.href} prefetch>
                                            <item.icon />
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                )}
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
