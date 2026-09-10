import { Link } from '@inertiajs/react';
import {
    Beaker,
    CalendarPlus,
    ClipboardCheck,
    PackagePlus,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { index as formulasIndex } from '@/routes/formulas';
import { create as createReceipt } from '@/routes/goods-receipts';
import { create as createPlan } from '@/routes/plans';
import { index as qcIndex } from '@/routes/qc';

function timeOfDay(date: Date): string {
    const h = date.getHours();
    if (h < 12) return 'Good morning';
    if (h < 17) return 'Good afternoon';
    return 'Good evening';
}

export function Hero({
    firstName,
    date,
    headlines,
    actions,
}: {
    firstName: string;
    date: string;
    headlines: string[];
    actions: {
        plan: boolean;
        receive: boolean;
        qc: boolean;
        formulas: boolean;
    };
}) {
    const now = new Date(date);

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
                    <p className="text-muted-foreground text-sm">
                        {now.toLocaleDateString('en-IN', {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                        })}
                    </p>
                    <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
                        {timeOfDay(now)}, {firstName}
                    </h1>
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
