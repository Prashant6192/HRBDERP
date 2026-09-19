import { Link, usePage } from '@inertiajs/react';
import {
    Boxes,
    Factory,
    Home,
    IndianRupee,
    LayoutGrid,
    PackageCheck,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    batches,
    billing,
    index as home,
    materials,
    production,
} from '@/routes/management';
import type { SharedData } from '@/types';

const TABS: { href: string; icon: LucideIcon; label: string; match: string }[] =
    [
        { href: home().url, icon: Home, label: 'Home', match: '/m' },
        {
            href: production().url,
            icon: Factory,
            label: 'Making',
            match: '/m/production',
        },
        {
            href: materials().url,
            icon: Boxes,
            label: 'Stock',
            match: '/m/materials',
        },
        {
            href: batches().url,
            icon: PackageCheck,
            label: 'Ready',
            match: '/m/batches',
        },
        {
            href: billing().url,
            icon: IndianRupee,
            label: 'To bill',
            match: '/m/billing',
        },
    ];

/**
 * The management view: one column, big numbers, a tab bar under the
 * thumb. Read-only by design; the full ERP is one tap away.
 */
export default function ManagementLayout({
    children,
}: {
    children: ReactNode;
}) {
    const { auth, erp } = usePage<SharedData>().props;
    useFlashToast();

    const path =
        typeof window !== 'undefined'
            ? window.location.pathname.replace(/\/$/, '')
            : '/m';

    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col">
            <header className="bg-card sticky top-0 z-20 flex h-14 items-center justify-between border-b px-4">
                <div>
                    <p className="text-sm leading-tight font-semibold">
                        {erp?.company ?? 'ERP'} · Management
                    </p>
                    <p className="text-muted-foreground text-xs leading-tight">
                        {auth?.user?.name}
                    </p>
                </div>
                <Link
                    href={dashboard()}
                    className="text-muted-foreground hover:bg-muted flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm"
                    title="Full ERP"
                >
                    <LayoutGrid className="size-5" />
                    <span className="hidden sm:inline">Full ERP</span>
                </Link>
            </header>
            <main className="mx-auto w-full max-w-lg flex-1 px-4 pt-4 pb-24">
                {children}
            </main>
            <nav className="bg-card fixed inset-x-0 bottom-0 z-20 border-t pb-[env(safe-area-inset-bottom)]">
                <ul className="mx-auto grid max-w-lg grid-cols-5">
                    {TABS.map((t) => {
                        const active =
                            t.match === '/m'
                                ? path === '/m'
                                : path.startsWith(t.match);

                        return (
                            <li key={t.href}>
                                <Link
                                    href={t.href}
                                    className={cn(
                                        'flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium',
                                        active
                                            ? 'text-primary'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    <t.icon className="size-5" />
                                    {t.label}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>
        </div>
    );
}
