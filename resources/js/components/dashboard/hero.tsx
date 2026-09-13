import { Link } from '@inertiajs/react';
import {
    Beaker,
    CalendarPlus,
    ClipboardCheck,
    Clock,
    LogIn,
    PackagePlus,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { index as formulasIndex } from '@/routes/formulas';
import { create as createReceipt } from '@/routes/goods-receipts';
import { create as createPlan } from '@/routes/plans';
import { index as qcIndex } from '@/routes/qc';

function hourIn(date: Date, timeZone: string): number {
    return Number(
        new Intl.DateTimeFormat('en-GB', {
            hour: 'numeric',
            hour12: false,
            timeZone,
        }).format(date),
    );
}

function timeOfDay(date: Date, timeZone: string): string {
    const h = hourIn(date, timeZone);
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
}

/**
 * The factory's wall clock: the browser's current time, shown in the
 * factory's zone and ticking every second. The server's timestamp only
 * seeds it so the first paint is right before the first tick.
 */
function useLiveClock(serverIso: string): Date {
    const [now, setNow] = useState(() => new Date(serverIso));
    useEffect(() => {
        setNow(new Date());
        const id = window.setInterval(() => setNow(new Date()), 1000);
        return () => window.clearInterval(id);
    }, []);
    return now;
}

export function Hero({
    firstName,
    date,
    timezone,
    signedInAt,
    signedInFrom,
    headlines,
    actions,
}: {
    firstName: string;
    date: string;
    timezone: string;
    signedInAt: string | null;
    signedInFrom: string | null;
    headlines: string[];
    actions: {
        plan: boolean;
        receive: boolean;
        qc: boolean;
        formulas: boolean;
    };
}) {
    const now = useLiveClock(date);
    const signedIn = signedInAt ? new Date(signedInAt) : null;
    const dayFormat = new Intl.DateTimeFormat('en-IN', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: timezone,
    });
    const timeFormat = new Intl.DateTimeFormat('en-IN', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
        timeZone: timezone,
    });
    const signedInFormat = new Intl.DateTimeFormat('en-IN', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hour12: true,
        timeZone: timezone,
    });

    return (
        <section className="bg-card relative overflow-hidden rounded-2xl border">
            {/* Warm accent block, as on the design: shapes, no picture to load. */}
            <div
                aria-hidden
                className="from-primary/20 via-primary/5 pointer-events-none absolute inset-y-0 right-0 hidden w-2/5 bg-gradient-to-l to-transparent lg:block"
            >
                <div className="bg-primary/25 absolute top-6 right-10 size-28 rounded-full blur-sm" />
                <div className="bg-chart-2/25 absolute right-36 bottom-4 size-16 rounded-full" />
                <div className="bg-chart-4/40 absolute right-8 bottom-10 size-10 rotate-12 rounded-lg" />
            </div>

            <div className="relative grid gap-6 p-6 sm:p-8 lg:grid-cols-5">
                <div className="lg:col-span-3">
                    <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                        <span>{dayFormat.format(now)}</span>
                        <span
                            className="inline-flex items-center gap-1 font-mono tabular-nums"
                            data-testid="live-clock"
                        >
                            <Clock className="size-3.5" />
                            {timeFormat.format(now)}
                        </span>
                    </div>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                        {timeOfDay(now, timezone)}, {firstName}
                    </h1>
                    {signedIn && (
                        <p className="text-muted-foreground mt-1 inline-flex items-center gap-1.5 text-xs">
                            <LogIn className="size-3.5" />
                            Signed in {signedInFormat.format(signedIn)}
                            {signedInFrom ? ` from ${signedInFrom}` : ''}
                        </p>
                    )}
                    {headlines.length > 0 ? (
                        <ul className="mt-3 space-y-1 text-sm">
                            {headlines.map((line, i) => (
                                <li key={i} className="flex items-start gap-2">
                                    <span className="bg-primary mt-1.5 size-1.5 shrink-0 rounded-full" />
                                    <span>{line}</span>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground mt-3 text-sm">
                            Here is where the factory stands today.
                        </p>
                    )}

                    <div className="mt-5 flex flex-wrap gap-2">
                        {actions.plan && (
                            <Button asChild size="sm">
                                <Link href={createPlan()}>
                                    <CalendarPlus className="size-4" />
                                    Plan a batch
                                </Link>
                            </Button>
                        )}
                        {actions.receive && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={createReceipt()}>
                                    <PackagePlus className="size-4" />
                                    Book in a delivery
                                </Link>
                            </Button>
                        )}
                        {actions.qc && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={qcIndex()}>
                                    <ClipboardCheck className="size-4" />
                                    QC checkpoint
                                </Link>
                            </Button>
                        )}
                        {actions.formulas && (
                            <Button asChild size="sm" variant="ghost">
                                <Link href={formulasIndex()}>
                                    <Beaker className="size-4" />
                                    Formulas
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </section>
    );
}
