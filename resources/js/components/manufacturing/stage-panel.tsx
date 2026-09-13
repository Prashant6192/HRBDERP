import { useForm } from '@inertiajs/react';
import { Check, Gauge } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { stage as stageRoute } from '@/routes/manufacturing';

export type StageSummary = {
    stage: string | null;
    stage_label: string | null;
    progress: number;
    overall: number;
    updated_at: string | null;
    stages: { value: string; label: string; order: number }[];
    events: {
        id: number;
        stage: string;
        stage_label: string;
        progress: number;
        note: string | null;
        by: string | null;
        at: string;
    }[];
};

function when(iso: string): string {
    return new Date(iso).toLocaleString('en-IN', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Real-time batch progress: the stepper, the bar, the form the floor uses
 * to say where the batch is, and the trail of readings.
 */
export function StagePanel({
    orderId,
    status,
    stages,
    canRecord,
}: {
    orderId: number;
    status: string;
    stages: StageSummary;
    canRecord: boolean;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        stage: stages.stage ?? 'weighing',
        progress: String(stages.progress ?? 0),
        note: '',
    });

    const currentOrder =
        stages.stages.find((s) => s.value === stages.stage)?.order ?? 0;
    const steps = stages.stages.filter((s) => s.value !== 'completed');

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(stageRoute(orderId).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('note', '');
                setOpen(false);
            },
        });
    };

    if (status !== 'in_progress' && status !== 'completed') {
        return null;
    }

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 className="inline-flex items-center gap-2 font-semibold">
                        <Gauge className="text-primary size-4" />
                        Batch progress
                    </h2>
                    <p className="text-muted-foreground text-sm">
                        {stages.stage_label
                            ? `${stages.stage_label} · ${stages.progress}%`
                            : 'Not yet recorded'}
                        {stages.updated_at
                            ? ` · updated ${when(stages.updated_at)}`
                            : ''}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <span className="text-2xl font-semibold tabular-nums">
                        {stages.overall}%
                    </span>
                    {canRecord && (
                        <Button
                            size="sm"
                            variant={open ? 'secondary' : 'default'}
                            onClick={() => setOpen((o) => !o)}
                        >
                            Record stage
                        </Button>
                    )}
                </div>
            </div>

            <div className="px-5 py-4">
                <div
                    className="bg-muted h-2 w-full overflow-hidden rounded-full"
                    role="progressbar"
                    aria-valuenow={stages.overall}
                    aria-valuemin={0}
                    aria-valuemax={100}
                >
                    <div
                        className="bg-primary h-full transition-[width]"
                        style={{ width: `${stages.overall}%` }}
                    />
                </div>
                <ol className="mt-4 grid grid-cols-4 gap-2 text-xs sm:grid-cols-8">
                    {steps.map((s) => {
                        const done =
                            status === 'completed' ||
                            s.order < currentOrder ||
                            (s.order === currentOrder &&
                                stages.progress >= 100);
                        const current =
                            status !== 'completed' && s.order === currentOrder;
                        return (
                            <li
                                key={s.value}
                                className={cn(
                                    'flex flex-col items-center gap-1 text-center',
                                    done &&
                                        'text-emerald-700 dark:text-emerald-300',
                                    current && 'text-primary font-semibold',
                                    !done &&
                                        !current &&
                                        'text-muted-foreground',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-6 items-center justify-center rounded-full border text-[10px]',
                                        done &&
                                            'border-emerald-600 bg-emerald-500/15',
                                        current &&
                                            'border-primary bg-primary/10',
                                    )}
                                >
                                    {done ? (
                                        <Check className="size-3" />
                                    ) : current ? (
                                        `${stages.progress}%`
                                    ) : (
                                        s.order
                                    )}
                                </span>
                                <span>{s.label}</span>
                            </li>
                        );
                    })}
                </ol>

                {open && canRecord && (
                    <form
                        onSubmit={submit}
                        className="bg-muted/30 mt-4 grid gap-3 rounded-lg border p-4 sm:grid-cols-[1fr_8rem_2fr_auto] sm:items-end"
                    >
                        <div>
                            <Label htmlFor="stage">Stage</Label>
                            <Select
                                value={form.data.stage}
                                onValueChange={(v) => form.setData('stage', v)}
                            >
                                <SelectTrigger id="stage" className="mt-1">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {steps.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.stage} />
                        </div>
                        <div>
                            <Label htmlFor="progress">Done %</Label>
                            <Input
                                id="progress"
                                inputMode="numeric"
                                className="mt-1"
                                value={form.data.progress}
                                onChange={(e) =>
                                    form.setData('progress', e.target.value)
                                }
                            />
                            <InputError message={form.errors.progress} />
                        </div>
                        <div>
                            <Label htmlFor="note">Note</Label>
                            <Input
                                id="note"
                                className="mt-1"
                                placeholder="Optional, e.g. viscosity checked"
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                            <InputError message={form.errors.note} />
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </form>
                )}

                {stages.events.length > 0 && (
                    <ul className="mt-4 divide-y text-sm">
                        {stages.events.slice(0, 8).map((e) => (
                            <li
                                key={e.id}
                                className="flex items-start justify-between gap-3 py-1.5"
                            >
                                <span>
                                    <span className="font-medium">
                                        {e.stage_label}
                                    </span>{' '}
                                    <span className="tabular-nums">
                                        {e.progress}%
                                    </span>
                                    {e.note && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {e.note}
                                        </span>
                                    )}
                                </span>
                                <span className="text-muted-foreground shrink-0 text-xs">
                                    {when(e.at)}
                                    {e.by ? ` · ${e.by}` : ''}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
