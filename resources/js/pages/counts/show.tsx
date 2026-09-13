import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, Send, XCircle } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Scanner } from '@/components/floor/scanner';
import InputError from '@/components/input-error';
import { StatTile } from '@/components/intelligence/facility-filter';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { rupees } from '@/lib/intelligence';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    approve,
    cancel,
    index,
    line as lineRoute,
    show,
    submit as submitRoute,
} from '@/routes/counts';

type Line = {
    id: number;
    item_id: number;
    code: string | null;
    name: string | null;
    unit: string | null;
    lot_id: number | null;
    batch_number: string | null;
    scan_code: string | null;
    system: string;
    counted: string | null;
    variance: string | null;
    note: string | null;
    counted_by: string | null;
    posted: boolean;
};

type Count = {
    id: number;
    number: string;
    store: string | null;
    store_id: number;
    status: 'counting' | 'submitted' | 'approved' | 'cancelled';
    status_label: string;
    notes: string | null;
    started_by: string | null;
    started_at: string | null;
    submitted_by: string | null;
    approved_by: string | null;
    approved_at: string | null;
};

const STATUS = {
    counting: 'warning',
    submitted: 'info',
    approved: 'success',
    cancelled: 'muted',
} as const;

export default function CountShow({
    count,
    lines,
    accuracy,
    can,
}: {
    count: Count;
    lines: Line[];
    accuracy: {
        lines: number;
        counted: number;
        accurate: number;
        accuracy_percent: string | null;
        variance_value: string;
    };
    can: { count: boolean; submit: boolean; approve: boolean; cancel: boolean };
}) {
    const [target, setTarget] = useState<Line | null>(null);
    const [scanned, setScanned] = useState<string | null>(null);
    const form = useForm({
        code: '',
        item_id: '',
        lot_id: '',
        counted: '',
        note: '',
    });

    const begin = (l: Line) => {
        setTarget(l);
        setScanned(null);
        form.setData({
            code: '',
            item_id: String(l.item_id),
            lot_id: l.lot_id ? String(l.lot_id) : '',
            counted: l.counted ?? '',
            note: l.note ?? '',
        });
    };

    const onCode = (code: string) => {
        const hit = lines.find(
            (l) =>
                l.scan_code === code ||
                l.batch_number === code.replace(/^LOT:/i, ''),
        );
        setScanned(code);
        if (hit) {
            begin(hit);
            form.setData('code', code);
        } else {
            setTarget(null);
            form.setData({
                code,
                item_id: '',
                lot_id: '',
                counted: '',
                note: '',
            });
        }
    };

    const save = (e: FormEvent) => {
        e.preventDefault();
        form.post(lineRoute(count.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setTarget(null);
                setScanned(null);
                form.reset();
            },
        });
    };

    const remaining = lines.filter((l) => l.counted === null).length;

    return (
        <>
            <Head title={count.number} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`${count.number} · ${count.store ?? ''}`}
                    description={`Started by ${count.started_by ?? '—'} on ${count.started_at ? new Date(count.started_at).toLocaleDateString('en-IN') : '—'}${count.notes ? ` · ${count.notes}` : ''}`}
                    actions={
                        <>
                            <StatusBadge variant={STATUS[count.status]}>
                                {count.status_label}
                            </StatusBadge>
                            {can.submit && (
                                <ConfirmDialog
                                    trigger={
                                        <Button
                                            size="sm"
                                            disabled={remaining > 0}
                                        >
                                            <Send className="size-4" />
                                            Submit
                                        </Button>
                                    }
                                    title="Submit the count?"
                                    description="Someone other than the counters must approve it; only then are the differences posted."
                                    confirmLabel="Submit"
                                    action={() =>
                                        router.post(
                                            submitRoute(count.id).url,
                                            undefined,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            )}
                            {can.approve && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm">
                                            <CheckCircle2 className="size-4" />
                                            Approve and post
                                        </Button>
                                    }
                                    title="Approve the count?"
                                    description="Every difference between shelf and system is posted as an adjustment through the ledger, referencing this count."
                                    confirmLabel="Approve"
                                    action={() =>
                                        router.post(
                                            approve(count.id).url,
                                            undefined,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            )}
                            {can.cancel && (
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" variant="ghost">
                                            <XCircle className="size-4" />
                                            Cancel
                                        </Button>
                                    }
                                    title="Cancel the count?"
                                    description="Nothing is posted; what was counted stays on record."
                                    confirmLabel="Cancel count"
                                    destructive
                                    action={() =>
                                        router.post(
                                            cancel(count.id).url,
                                            undefined,
                                            { preserveScroll: true },
                                        )
                                    }
                                />
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile
                        label="Lines"
                        value={accuracy.lines}
                        hint={`${accuracy.counted} counted · ${remaining} to go`}
                    />
                    <StatTile
                        label="Inventory accuracy"
                        value={
                            accuracy.accuracy_percent === null
                                ? '—'
                                : `${accuracy.accuracy_percent}%`
                        }
                        tone={
                            accuracy.accuracy_percent === null
                                ? 'default'
                                : Number(accuracy.accuracy_percent) >= 98
                                  ? 'success'
                                  : Number(accuracy.accuracy_percent) >= 90
                                    ? 'warning'
                                    : 'danger'
                        }
                        hint="lines where shelf matched system"
                    />
                    <StatTile
                        label="Matched"
                        value={accuracy.accurate}
                        tone="success"
                    />
                    <StatTile
                        label="Variance value"
                        value={rupees(accuracy.variance_value)}
                        tone={
                            Number(accuracy.variance_value) > 0
                                ? 'warning'
                                : 'success'
                        }
                        hint="absolute, at batch cost"
                    />
                </div>

                {can.count && (
                    <section className="bg-card grid gap-4 rounded-2xl border p-5 lg:grid-cols-2">
                        <div>
                            <h2 className="mb-2 flex items-center gap-2 font-semibold">
                                <ClipboardList className="text-primary size-4" />
                                Scan a batch, then enter what you found
                            </h2>
                            <Scanner
                                onCode={onCode}
                                autoStart={false}
                                placeholder="Scan the batch sticker or type its number…"
                            />
                            {scanned && !target && (
                                <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                    {scanned} is not on this sheet; entering a
                                    quantity adds it as a batch found on the
                                    shelf but not in the system.
                                </p>
                            )}
                        </div>
                        <form onSubmit={save} className="space-y-3">
                            <div className="rounded-xl border p-3 text-sm">
                                {target ? (
                                    <>
                                        <div className="font-medium">
                                            {target.name}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {target.batch_number ?? 'no batch'}{' '}
                                            · system says {qty(target.system)}{' '}
                                            {target.unit ?? ''}
                                        </div>
                                    </>
                                ) : scanned ? (
                                    <div className="font-medium">{scanned}</div>
                                ) : (
                                    <div className="text-muted-foreground">
                                        Scan a batch or pick a line below.
                                    </div>
                                )}
                            </div>
                            <div>
                                <Label htmlFor="counted">
                                    Counted quantity
                                </Label>
                                <Input
                                    id="counted"
                                    inputMode="decimal"
                                    className="mt-1 h-11 text-lg"
                                    value={form.data.counted}
                                    onChange={(e) =>
                                        form.setData('counted', e.target.value)
                                    }
                                    disabled={!target && !scanned}
                                />
                                <InputError
                                    message={
                                        form.errors.counted ?? form.errors.code
                                    }
                                />
                            </div>
                            <div>
                                <Label htmlFor="note">Note</Label>
                                <Input
                                    id="note"
                                    className="mt-1"
                                    value={form.data.note}
                                    onChange={(e) =>
                                        form.setData('note', e.target.value)
                                    }
                                    placeholder="e.g. damaged drum, rack B"
                                />
                            </div>
                            <Button
                                type="submit"
                                className="h-11 w-full"
                                disabled={
                                    form.processing ||
                                    (!target && !scanned) ||
                                    form.data.counted === ''
                                }
                            >
                                Save count
                            </Button>
                        </form>
                    </section>
                )}

                <div className="bg-card overflow-x-auto rounded-2xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Material</TableHead>
                                <TableHead>Batch</TableHead>
                                <TableHead className="text-right">
                                    System
                                </TableHead>
                                <TableHead className="text-right">
                                    Counted
                                </TableHead>
                                <TableHead className="text-right">
                                    Variance
                                </TableHead>
                                <TableHead>Note</TableHead>
                                <TableHead>By</TableHead>
                                {can.count && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {lines.map((l) => {
                                const v =
                                    l.variance === null
                                        ? null
                                        : Number(l.variance);
                                return (
                                    <TableRow
                                        key={l.id}
                                        className={cn(
                                            v !== null &&
                                                v !== 0 &&
                                                'bg-amber-500/5',
                                            target?.id === l.id &&
                                                'bg-primary/5',
                                        )}
                                    >
                                        <TableCell>
                                            <div className="font-medium">
                                                {l.name}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {l.code}
                                            </div>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {l.batch_number ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {qty(l.system)} {l.unit ?? ''}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {l.counted === null
                                                ? '—'
                                                : qty(l.counted)}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-medium tabular-nums',
                                                v !== null &&
                                                    v !== 0 &&
                                                    'text-amber-700 dark:text-amber-300',
                                            )}
                                        >
                                            {v === null
                                                ? '—'
                                                : v === 0
                                                  ? '✓'
                                                  : `${v > 0 ? '+' : ''}${qty(l.variance)}`}
                                            {l.posted && (
                                                <span className="text-muted-foreground ml-1 text-[10px]">
                                                    posted
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {l.note ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground text-xs">
                                            {l.counted_by ?? '—'}
                                        </TableCell>
                                        {can.count && (
                                            <TableCell className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => begin(l)}
                                                >
                                                    {l.counted === null
                                                        ? 'Count'
                                                        : 'Recount'}
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

CountShow.layout = ({ count }: { count: Count }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stock counts', href: index() },
        { title: count.number, href: show(count.id) },
    ],
});
