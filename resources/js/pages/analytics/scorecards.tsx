import { Head, router } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import {
    FacilityFilter,
    StatTile,
} from '@/components/intelligence/facility-filter';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { FacilityChip } from '@/lib/intelligence';
import { cn } from '@/lib/utils';
import { dashboard, scorecards as scorecardsRoute } from '@/routes';

type Tone = 'good' | 'warn' | 'bad' | 'neutral';

type Kpi = {
    key: string;
    label: string;
    value: string | null;
    unit: string | null;
    tone: Tone;
    hint: string;
};

type Department = {
    key: string;
    name: string;
    score: number | null;
    tone: Tone;
    kpis: Kpi[];
};

type Step = {
    key: string;
    department: string;
    label: string;
    volume: number;
    average_hours: number | null;
    longest_hours: number | null;
    open: number;
    oldest_open_hours: number | null;
};

type Scorecards = {
    period: { days: number; from: string; to: string };
    departments: Department[];
    processes: Step[];
    headline: {
        score: number | null;
        otif_percent: string | null;
        inventory_accuracy_percent: string | null;
        yield_percent: string | null;
        qc_turnaround_hours: string | null;
    };
};

const TONE_TEXT: Record<Tone, string> = {
    good: 'text-emerald-700 dark:text-emerald-300',
    warn: 'text-amber-700 dark:text-amber-300',
    bad: 'text-red-700 dark:text-red-300',
    neutral: 'text-foreground',
};

const TONE_RING: Record<Tone, string> = {
    good: 'border-emerald-500/40',
    warn: 'border-amber-500/40',
    bad: 'border-red-500/40',
    neutral: '',
};

const TILE_TONE = {
    good: 'success',
    warn: 'warning',
    bad: 'danger',
    neutral: 'default',
} as const;

const toneOf = (value: string | null, good: number, warn: number): Tone =>
    value === null
        ? 'neutral'
        : Number(value) >= good
          ? 'good'
          : Number(value) >= warn
            ? 'warn'
            : 'bad';

const hours = (h: number | null) =>
    h === null
        ? '—'
        : h >= 48
          ? `${(h / 24).toFixed(1)} d`
          : `${h.toFixed(1)} h`;

export default function Scorecards({
    scorecards,
    filters,
    facilities,
}: {
    scorecards: Scorecards;
    filters: { facility: number | null; days: number };
    facilities: FacilityChip[];
}) {
    const go = (next: Partial<typeof filters>) =>
        router.get(
            scorecardsRoute().url,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const h = scorecards.headline;

    return (
        <>
            <Head title="Scorecards" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Department scorecards"
                    description={`${scorecards.period.from} to ${scorecards.period.to}. Every figure is read from the documents the departments keep: plans, requests, receipts, QC decisions, batches, transfers and counts. The process is measured, not the person.`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <FacilityFilter
                                facilities={facilities}
                                value={filters.facility}
                                onChange={(facility) => go({ facility })}
                            />
                            <div className="flex rounded-lg border p-0.5">
                                {[7, 30, 90, 365].map((d) => (
                                    <Button
                                        key={d}
                                        size="sm"
                                        variant={
                                            filters.days === d
                                                ? 'secondary'
                                                : 'ghost'
                                        }
                                        onClick={() => go({ days: d })}
                                    >
                                        {d === 365 ? '1 year' : `${d} days`}
                                    </Button>
                                ))}
                            </div>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <StatTile
                        label="Factory score"
                        value={h.score === null ? '—' : `${h.score}`}
                        tone={
                            h.score === null
                                ? 'default'
                                : h.score >= 90
                                  ? 'success'
                                  : h.score >= 70
                                    ? 'warning'
                                    : 'danger'
                        }
                        hint="average of the department scores"
                    />
                    <StatTile
                        label="Dispatch OTIF"
                        value={
                            h.otif_percent === null ? '—' : `${h.otif_percent}%`
                        }
                        tone={TILE_TONE[toneOf(h.otif_percent, 95, 80)]}
                        hint="client jobs on time and in full"
                    />
                    <StatTile
                        label="Inventory accuracy"
                        value={
                            h.inventory_accuracy_percent === null
                                ? '—'
                                : `${h.inventory_accuracy_percent}%`
                        }
                        tone={
                            TILE_TONE[
                                toneOf(h.inventory_accuracy_percent, 98, 90)
                            ]
                        }
                        hint="from approved stock counts"
                    />
                    <StatTile
                        label="Yield"
                        value={
                            h.yield_percent === null
                                ? '—'
                                : `${h.yield_percent}%`
                        }
                        tone={TILE_TONE[toneOf(h.yield_percent, 95, 90)]}
                        hint="average across completed batches"
                    />
                    <StatTile
                        label="QC turnaround"
                        value={
                            h.qc_turnaround_hours === null
                                ? '—'
                                : `${h.qc_turnaround_hours} h`
                        }
                        hint="sample logged to decision"
                    />
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {scorecards.departments.map((d) => (
                        <section
                            key={d.key}
                            className={cn(
                                'bg-card rounded-2xl border p-5',
                                TONE_RING[d.tone],
                            )}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="font-semibold">{d.name}</h2>
                                <span
                                    className={cn(
                                        'text-2xl font-semibold tabular-nums',
                                        TONE_TEXT[d.tone],
                                    )}
                                >
                                    {d.score === null ? '—' : d.score}
                                </span>
                            </div>
                            <dl className="mt-3 space-y-2.5">
                                {d.kpis.map((k) => (
                                    <div
                                        key={k.key}
                                        className="flex items-baseline justify-between gap-3"
                                    >
                                        <dt className="min-w-0">
                                            <span className="text-sm">
                                                {k.label}
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                {k.hint}
                                            </span>
                                        </dt>
                                        <dd
                                            className={cn(
                                                'shrink-0 text-lg font-semibold tabular-nums',
                                                TONE_TEXT[k.tone],
                                            )}
                                        >
                                            {k.value === null
                                                ? '—'
                                                : `${k.value}${k.unit === '%' ? '%' : k.unit ? ` ${k.unit}` : ''}`}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    ))}
                </div>

                <section className="bg-card overflow-x-auto rounded-2xl border">
                    <div className="border-b px-5 py-4">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Gauge className="text-primary size-4" />
                            Process performance
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            How much went through each step, how long it took,
                            and what is waiting at it now. The oldest open item
                            is where the queue is stuck.
                        </p>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Step</TableHead>
                                <TableHead>Department</TableHead>
                                <TableHead className="text-right">
                                    Completed
                                </TableHead>
                                <TableHead className="text-right">
                                    Average
                                </TableHead>
                                <TableHead className="text-right">
                                    Longest
                                </TableHead>
                                <TableHead className="text-right">
                                    Waiting now
                                </TableHead>
                                <TableHead className="text-right">
                                    Oldest waiting
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {scorecards.processes.map((s) => (
                                <TableRow key={s.key}>
                                    <TableCell className="font-medium">
                                        {s.label}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {s.department}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {s.volume}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {hours(s.average_hours)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {hours(s.longest_hours)}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right tabular-nums',
                                            s.open > 0 && 'font-semibold',
                                        )}
                                    >
                                        {s.open}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right tabular-nums',
                                            s.oldest_open_hours !== null &&
                                                s.oldest_open_hours > 48 &&
                                                'text-red-700 dark:text-red-300',
                                        )}
                                    >
                                        {hours(s.oldest_open_hours)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </>
    );
}

Scorecards.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Scorecards', href: scorecardsRoute() },
    ],
};
