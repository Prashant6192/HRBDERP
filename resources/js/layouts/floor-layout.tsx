import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Home, LayoutGrid, ScanLine } from 'lucide-react';
import type { ReactNode } from 'react';
import { useFlashToast } from '@/hooks/use-flash-toast';
import { dashboard } from '@/routes';
import { index as floorIndex, scan } from '@/routes/floor';
import type { SharedData } from '@/types';

/**
 * The floor mode: one column, big targets, no sidebar. Built for a phone
 * held in one hand with the other on a drum.
 */
export default function FloorLayout({ children }: { children: ReactNode }) {
    const { auth, erp } = usePage<SharedData>().props;
    useFlashToast();

    const atHome =
        typeof window !== 'undefined' &&
        window.location.pathname.replace(/\/$/, '') === '/floor';

    return (
        <div className="bg-background text-foreground flex min-h-svh flex-col">
            <header className="bg-card sticky top-0 z-20 flex h-14 items-center justify-between border-b px-4">
                <div className="flex items-center gap-2">
                    {atHome ? (
                        <Home className="text-primary size-5" />
                    ) : (
                        <Link
                            href={floorIndex()}
                            className="hover:bg-muted -ml-2 rounded-lg p-2"
                            aria-label="Back to the floor home"
                        >
                            <ArrowLeft className="size-5" />
                        </Link>
                    )}
                    <div>
                        <p className="text-sm leading-tight font-semibold">
                            {erp?.company ?? 'ERP'} · Floor
                        </p>
                        <p className="text-muted-foreground text-xs leading-tight">
                            {auth?.user?.name}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-1">
                    <Link
                        href={scan()}
                        className="bg-primary text-primary-foreground flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium"
                    >
                        <ScanLine className="size-4" />
                        Scan
                    </Link>
                    <Link
                        href={dashboard()}
                        className="hover:bg-muted rounded-lg p-2"
                        aria-label="Full ERP"
                        title="Full ERP"
                    >
                        <LayoutGrid className="size-5" />
                    </Link>
                </div>
            </header>
            <main className="mx-auto w-full max-w-lg flex-1 px-4 pt-4 pb-10">
                {children}
            </main>
        </div>
    );
}
