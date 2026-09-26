import { Link, usePage } from '@inertiajs/react';
import { AuthShowcase } from '@/components/auth/auth-showcase';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps, SharedData } from '@/types';

/**
 * The way into the ERP: one dark card, the form on the left and what the
 * system is for on the right. It stays dark whatever the browser prefers
 * — this is the front door, not a screen anyone works in all day — so
 * the shell carries its own palette and everything inside it resolves
 * against that. See `.auth-shell` in resources/css/app.css.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { erp, name } = usePage<SharedData>().props;
    const company = erp?.company ?? name;

    return (
        <div className="auth-shell dark bg-background text-foreground relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-4 sm:p-6 lg:p-10">
            {/* Two soft lights behind the card, so it is not floating on flat black. */}
            <div
                aria-hidden
                className="pointer-events-none absolute -top-40 -right-32 size-[34rem] rounded-full bg-sky-500/10 blur-3xl"
            />
            <div
                aria-hidden
                className="bg-primary/10 pointer-events-none absolute -bottom-48 -left-40 size-[38rem] rounded-full blur-3xl"
            />

            <main className="bg-card relative w-full max-w-5xl overflow-hidden rounded-3xl border shadow-2xl shadow-black/40">
                <div className="grid lg:min-h-[38rem] lg:grid-cols-2">
                    <div className="flex flex-col p-7 sm:p-10 lg:p-12">
                        <Link
                            href={home()}
                            className="mb-10 inline-flex items-center gap-2.5 self-start rounded-md focus-visible:ring-2 focus-visible:ring-[var(--ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--card)] focus-visible:outline-none"
                        >
                            <span className="bg-primary text-primary-foreground flex size-8 items-center justify-center rounded-lg">
                                <AppLogoIcon className="size-4.5 fill-current" />
                            </span>
                            <span className="text-sm font-semibold tracking-tight">
                                {company}
                            </span>
                        </Link>

                        <div className="flex flex-1 flex-col justify-center">
                            <div className="mb-8 text-center">
                                <h1 className="text-3xl font-semibold tracking-tight">
                                    {title}
                                </h1>
                                {description && (
                                    <p className="text-muted-foreground mt-2 text-sm text-balance">
                                        {description}
                                    </p>
                                )}
                            </div>

                            {children}
                        </div>
                    </div>

                    <div className="hidden p-3 lg:block">
                        <AuthShowcase className="h-full" />
                    </div>
                </div>
            </main>

            <p className="text-muted-foreground relative mt-6 text-xs">
                {company} · works management
                {erp?.version ? ` · v${erp.version}` : ''}
            </p>
        </div>
    );
}
