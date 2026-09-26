import { Link, usePage } from '@inertiajs/react';
import { usePermissions } from '@/hooks/use-permissions';
import type { SharedData } from '@/types';
import { index as onlineOrders } from '@/routes/online-orders';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';

export function AppSidebar() {
    const { isAgency } = usePermissions();
    const version = usePage<SharedData>().props.erp?.version;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link
                                href={isAgency ? onlineOrders() : dashboard()}
                                prefetch
                            >
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent className="gap-4">
                <NavMain />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
                {version && (
                    <p className="text-muted-foreground px-2 text-[11px] group-data-[collapsible=icon]:hidden">
                        HRBD ERP v{version}
                    </p>
                )}
            </SidebarFooter>
        </Sidebar>
    );
}
