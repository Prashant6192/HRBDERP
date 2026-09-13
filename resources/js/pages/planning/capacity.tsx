import { Head, router } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { capacity } from '@/routes/planning';

type Week = {
    week_start: string;
    label: string;
    capacity_kg: string | null;
    booked_kg: string;
    utilisation_percent: number | null;
    level: 'unknown' | 'over' | 'tight' | 'busy' | 'open';
};

type FacilityRow = {
    facility_id: number;
    code: string;
    name: string;
    daily_capacity_kg: string | null;
    weeks: Week[];
    bookings: {
        number: string;
        label: string;
        kg: string;
        start: string;
        end: string;
        status: string;
        kind: 'order' | 'plan';
    }[];
};

const LEVEL: Record<Week['level'], string> = {
    unknown: 'bg-muted',
    over: 'bg-red-500',
    tight: 'bg-amber-500',
    busy: 'bg-primary',
    open: 'bg-emerald-500',
};

const LEVEL_LABEL: Record<Week['level'], string> = {
    unknown: 'No capacity set',
    over: 'Over capacity',
    tight: 'Tight',
    busy: 'Busy',
    open: 'Open',
};

export default function CapacityPlanning({
    facilities,
    filters,
}: {
    facilities: FacilityRow[];
    filters: { weeks: number };
}) {
    return (
        <>
            <Head title="Capacity" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Capacity planning"
                    description="Plant capacity against booked production, week by week. Every approved or running batch and every checked plan is a booking from its start date for as many days as it needs at the facility's daily capacity."
                    actions={
                        <div className="flex gap-1">
                            {[4, 6, 8, 12].map((w) => (
                                <Button
                                    key={w}
                                    size="sm"
                                    variant={
                                        filters.weeks === w
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        router.get(
                                            capacity().url,
                                            { weeks: w },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    {w} weeks
                                </Button>
                            ))}
                        </div>
                    }
                />

                {facilities.length === 0 && (
                    <p className="text-muted-foreground bg-card rounded-2xl border p-10 text-center text-sm">
                        No facility has manufacturing enabled.
                    </p>
                )}

                {facilities.map((f) => (
                    <section
                        key={f.facility_id}
                        className="bg-card rounded-2xl border"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                            <div>
                                <h2 className="inline-flex items-center gap-2 font-semibold">
                                    <Gauge className="text-primary size-4" />
                                    {f.name}
                                </h2>
                                <p className="text-muted-foreground text-sm">
                                    {f.daily_capacity_kg
                                        ? `${qty(f.daily_capacity_kg)} KG a day, six days a week`
                                        : 'Daily capacity not set on the facility; bookings are shown without utilisation.'}
                                </p>
                            </div>
                            <span className="text-muted-foreground text-xs">
                                {f.bookings.length} booking
                                {f.bookings.length === 1 ? '' : 's'}
                            </span>
                        </div>

                        <div className="grid gap-3 px-5 py-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                            {f.weeks.map((w) => (
                                <div
                                    key={w.week_start}
                                    className="rounded-xl border p-3"
                                >
                                    <div className="flex items-center justify-between text-xs">
                                        <span className="font-medium">
                                            {w.label}
                                        </span>
                                        <StatusBadge
                                            variant={
                                                w.level === 'over'
                                                    ? 'destructive'
                                                    : w.level === 'tight'
                                                      ? 'warning'
                                                      : w.level === 'open'
                                                        ? 'success'
                                                        : w.level === 'busy'
                                                          ? 'info'
                                                          : 'muted'
                                            }
                                        >
                                            {LEVEL_LABEL[w.level]}
                                        </StatusBadge>
                                    </div>
                                    <p className="mt-2 text-2xl font-semibold tabular-nums">
                                        {w.utilisation_percent === null
                                            ? '—'
                                            : `${w.utilisation_percent}%`}
                                    </p>
                                    <div className="bg-muted mt-2 h-2 overflow-hidden rounded-full">
                                        <div
                                            className={cn(
                                                'h-full',
                                                LEVEL[w.level],
                                            )}
                                            style={{
                                                width: `${Math.min(100, w.utilisation_percent ?? 0)}%`,
                                            }}
                                        />
                                    </div>
                                    <p className="text-muted-foreground mt-1 text-xs tabular-nums">
                                        {qty(w.booked_kg, 0)} of{' '}
                                        {w.capacity_kg
                                            ? qty(w.capacity_kg, 0)
                                            : '—'}{' '}
                                        KG
                                    </p>
                                </div>
                            ))}
                        </div>

                        {f.bookings.length > 0 && (
                            <details className="border-t px-5 py-3 text-sm">
                                <summary className="cursor-pointer font-medium">
                                    Bookings
                                </summary>
                                <ul className="mt-2 divide-y">
                                    {f.bookings.map((b) => (
                                        <li
                                            key={b.number}
                                            className="flex flex-wrap items-center justify-between gap-2 py-1.5"
                                        >
                                            <span>
                                                <span className="font-medium">
                                                    {b.number}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {b.label}
                                                </span>
                                            </span>
                                            <span className="text-muted-foreground text-xs tabular-nums">
                                                {qty(b.kg, 0)} KG · {b.start}
                                                {b.end !== b.start
                                                    ? ` → ${b.end}`
                                                    : ''}{' '}
                                                ·{' '}
                                                {b.kind === 'plan'
                                                    ? 'plan'
                                                    : b.status.replace(
                                                          '_',
                                                          ' ',
                                                      )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </details>
                        )}
                    </section>
                ))}
            </div>
        </>
    );
}

CapacityPlanning.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Capacity', href: capacity() },
    ],
};
