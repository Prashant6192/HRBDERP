import { useForm } from '@inertiajs/react';
import { Minus, PackageX, Plus, Undo2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TONE_VARIANT, when } from '@/lib/dispatch';
import { courierName, describeParcel, type Parcel } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { lookup, store } from '@/routes/online-orders/returns';

export type Sent = {
    item_id: number;
    item: string;
    item_code: string;
    units: string;
};

export type Found = {
    shipment: Parcel;
    sent: Sent[];
    why_not: string | null;
    store_id: number;
};

export type RecentReturn = {
    id: number;
    number: string;
    awb: string | null;
    order_number: string | null;
    marketplace: string | null;
    kind_label: string;
    received_at: string;
    claim_status: string;
    claim_label: string;
    claim_tone: 'neutral' | 'info' | 'warning' | 'success' | 'danger';
    lines: {
        item_id: number;
        item: string | null;
        good: string;
        damaged: string;
        missing: string;
    }[];
};

export type Option = { value: string; label: string };

type Line = { item_id: number; good: string; damaged: string };

const KIND_HINT: Record<string, string> = {
    rto: 'The courier brought it back undelivered.',
    customer: 'The buyer received it and sent it back.',
};

/**
 * Finds the parcel a returning packet belongs to, by AWB or order number.
 */
export function useParcelLookup() {
    const [finding, setFinding] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [found, setFound] = useState<Found | null>(null);

    const find = async (value: string): Promise<Found | null> => {
        const code = value.trim();

        if (code === '') {
            return null;
        }

        setFinding(true);
        setProblem(null);
        setFound(null);

        try {
            const response = await fetch(lookup({ query: { code } }).url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = (await response.json()) as Found & {
                message?: string;
            };

            if (!response.ok || !data.shipment) {
                setProblem(data.message ?? 'No parcel has that code.');

                return null;
            }

            setFound(data);

            return data;
        } catch {
            setProblem('Could not reach the server. Try again.');

            return null;
        } finally {
            setFinding(false);
        }
    };

    const reset = () => {
        setFound(null);
        setProblem(null);
    };

    return { find, finding, problem, found, reset };
}

function Step({
    n,
    title,
    hint,
    children,
}: {
    n: number;
    title: string;
    hint?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-3">
            <div className="flex items-start gap-3">
                <span className="bg-primary/10 text-primary flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                    {n}
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    {hint && (
                        <p className="text-muted-foreground text-xs">{hint}</p>
                    )}
                </div>
            </div>
            <div className="sm:pl-9">{children}</div>
        </div>
    );
}

/**
 * A count a thumb can set: minus, the number, plus.
 */
function Stepper({
    value,
    max,
    onChange,
    label,
    tone,
    big,
}: {
    value: string;
    max: number;
    onChange: (value: string) => void;
    label: string;
    tone: 'good' | 'damaged';
    big: boolean;
}) {
    const n = Number(value || 0);
    const size = big ? 'h-12' : 'h-10';

    return (
        <div
            className={cn(
                'flex items-stretch overflow-hidden rounded-lg border',
                tone === 'good' ? 'border-emerald-600/40' : 'border-red-600/40',
            )}
        >
            <button
                type="button"
                onClick={() => onChange(String(Math.max(0, n - 1)))}
                disabled={n <= 0}
                aria-label={`One less ${label}`}
                className={cn(
                    'hover:bg-muted active:bg-muted flex shrink-0 items-center justify-center disabled:opacity-40',
                    big ? 'w-12' : 'w-10',
                )}
            >
                <Minus className="size-4" />
            </button>
            <input
                type="number"
                inputMode="numeric"
                min={0}
                step="any"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-label={label}
                className={cn(
                    'bg-background min-w-0 flex-1 [appearance:textfield] border-x text-center font-semibold tabular-nums outline-none focus:ring-2 focus:ring-inset [&::-webkit-inner-spin-button]:appearance-none',
                    size,
                    big ? 'text-xl' : 'text-base',
                    tone === 'good'
                        ? 'text-emerald-700 focus:ring-emerald-600/40 dark:text-emerald-300'
                        : 'text-red-700 focus:ring-red-600/40 dark:text-red-300',
                )}
            />
            <button
                type="button"
                onClick={() => onChange(String(n + 1))}
                disabled={n >= max}
                aria-label={`One more ${label}`}
                className={cn(
                    'hover:bg-muted active:bg-muted flex shrink-0 items-center justify-center disabled:opacity-40',
                    big ? 'w-12' : 'w-10',
                )}
            >
                <Plus className="size-4" />
            </button>
        </div>
    );
}

/**
 * The parcel that came back and the form to receive it. Used at a desk
 * and on the floor phone; `floor` makes the targets bigger and pins the
 * button to the bottom of the screen.
 */
export function ReturnPanel({
    found,
    kinds,
    stores,
    floor = false,
    onDone,
    onCancel,
}: {
    found: Found;
    kinds: Option[];
    stores: Option[];
    floor?: boolean;
    onDone: () => void;
    onCancel: () => void;
}) {
    const p = found.shipment;
    const inList = stores.some((s) => s.value === String(found.store_id));

    const form = useForm<{
        shipment_id: number;
        kind: string;
        into_store_id: string;
        lines: Line[];
        wrong_item: boolean;
        return_awb: string;
        notes: string;
        from: string;
    }>({
        shipment_id: p.id,
        kind: 'rto',
        into_store_id: inList
            ? String(found.store_id)
            : (stores[0]?.value ?? ''),
        lines: found.sent.map((s) => ({
            item_id: s.item_id,
            good: s.units,
            damaged: '0',
        })),
        wrong_item: false,
        return_awb: '',
        notes: '',
        from: floor ? 'floor' : '',
    });

    const sentFor = (l: Line) =>
        Number(found.sent.find((s) => s.item_id === l.item_id)?.units ?? 0);
    const missingFor = (l: Line) =>
        sentFor(l) - Number(l.good || 0) - Number(l.damaged || 0);

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    const allAs = (as: 'good' | 'damaged') =>
        form.setData(
            'lines',
            form.data.lines.map((l) => {
                const units = String(sentFor(l));

                return as === 'good'
                    ? { ...l, good: units, damaged: '0' }
                    : { ...l, good: '0', damaged: units };
            }),
        );

    const totals = form.data.lines.reduce(
        (t, l) => ({
            good: t.good + Number(l.good || 0),
            damaged: t.damaged + Number(l.damaged || 0),
            missing: t.missing + Math.max(0, missingFor(l)),
        }),
        { good: 0, damaged: 0, missing: 0 },
    );
    const tooMany = form.data.lines.some((l) => missingFor(l) < 0);
    const claimWillOpen =
        totals.damaged > 0 || totals.missing > 0 || form.data.wrong_item;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url, {
            preserveScroll: !floor,
            onSuccess: onDone,
        });
    };

    let step = 0;

    return (
        <section
            className={cn(
                'bg-card border',
                // No overflow clipping on the floor: it would stop the
                // receive bar from sticking to the bottom of the screen.
                floor ? 'rounded-2xl' : 'overflow-hidden rounded-xl',
            )}
        >
            <div
                className={cn(
                    'bg-muted/40 flex items-start justify-between gap-3 border-b p-4',
                    floor && 'rounded-t-2xl',
                )}
            >
                <div className="min-w-0">
                    <p className="font-mono text-lg font-semibold break-all">
                        {p.awb ?? p.order_number}
                    </p>
                    <p className="text-muted-foreground text-sm">
                        {[p.brand, p.marketplace, courierName(p.courier)]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    {p.order_number && (
                        <p className="text-muted-foreground text-sm break-all">
                            Order {p.order_number}
                        </p>
                    )}
                    <p className="mt-1 text-sm font-medium">
                        {describeParcel(p)}
                    </p>
                </div>
                <StatusBadge variant={TONE_VARIANT[p.status_tone]}>
                    {p.status_label}
                </StatusBadge>
            </div>

            {found.why_not ? (
                <div className="space-y-3 p-4">
                    <p className="rounded-lg bg-amber-500/15 p-3 text-sm font-medium text-amber-900 dark:text-amber-100">
                        {found.why_not}
                    </p>
                    <Button
                        variant="outline"
                        className={cn(floor && 'h-12 w-full')}
                        onClick={onCancel}
                    >
                        Scan another parcel
                    </Button>
                </div>
            ) : (
                <form onSubmit={submit}>
                    <div className="space-y-6 p-4">
                        <Step n={++step} title="Why did it come back?">
                            <div
                                role="radiogroup"
                                aria-label="Why it came back"
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {kinds.map((k) => {
                                    const on = form.data.kind === k.value;

                                    return (
                                        <button
                                            key={k.value}
                                            type="button"
                                            role="radio"
                                            aria-checked={on}
                                            onClick={() =>
                                                form.setData('kind', k.value)
                                            }
                                            className={cn(
                                                'flex items-start gap-3 rounded-lg border p-3 text-left',
                                                floor && 'py-4',
                                                on
                                                    ? 'border-primary bg-primary/5 ring-primary/30 ring-1'
                                                    : 'hover:bg-muted/60',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                                                    on && 'border-primary',
                                                )}
                                            >
                                                {on && (
                                                    <span className="bg-primary size-2 rounded-full" />
                                                )}
                                            </span>
                                            <span>
                                                <span className="block text-sm font-medium">
                                                    {k.label}
                                                </span>
                                                {KIND_HINT[k.value] && (
                                                    <span className="text-muted-foreground block text-xs">
                                                        {KIND_HINT[k.value]}
                                                    </span>
                                                )}
                                            </span>
                                        </button>
                                    );
                                })}
                            </div>
                        </Step>

                        <Step
                            n={++step}
                            title="Count what came back"
                            hint="Anything not counted as good or damaged is recorded as missing."
                        >
                            <div className="space-y-3">
                                <div className="grid grid-cols-2 gap-2 sm:flex sm:items-center">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className={cn(floor && 'h-10')}
                                        onClick={() => allAs('good')}
                                    >
                                        All good
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className={cn(floor && 'h-10')}
                                        onClick={() => allAs('damaged')}
                                    >
                                        All damaged
                                    </Button>
                                </div>

                                <div className="divide-y rounded-lg border">
                                    {form.data.lines.map((l, i) => {
                                        const s = found.sent.find(
                                            (x) => x.item_id === l.item_id,
                                        );
                                        const sent = sentFor(l);
                                        const missing = missingFor(l);

                                        return (
                                            <div
                                                key={l.item_id}
                                                className="space-y-3 p-3"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0">
                                                        <div className="font-medium">
                                                            {s?.item}
                                                        </div>
                                                        <div className="text-muted-foreground font-mono text-xs">
                                                            {s?.item_code}
                                                        </div>
                                                    </div>
                                                    <div className="shrink-0 text-right text-xs">
                                                        <div className="text-muted-foreground">
                                                            Sent{' '}
                                                            <b className="text-foreground text-sm tabular-nums">
                                                                {sent}
                                                            </b>
                                                        </div>
                                                        <div
                                                            className={cn(
                                                                'font-medium',
                                                                missing < 0 &&
                                                                    'text-red-700 dark:text-red-300',
                                                                missing > 0 &&
                                                                    'text-amber-700 dark:text-amber-300',
                                                                missing === 0 &&
                                                                    'text-muted-foreground',
                                                            )}
                                                        >
                                                            {missing < 0
                                                                ? 'More than sent'
                                                                : `Missing ${missing}`}
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="grid grid-cols-2 gap-3 md:max-w-md">
                                                    <div className="space-y-1">
                                                        <div className="text-[11px] font-medium tracking-wide text-emerald-700 uppercase dark:text-emerald-300">
                                                            Good
                                                        </div>
                                                        <Stepper
                                                            value={l.good}
                                                            max={
                                                                sent -
                                                                Number(
                                                                    l.damaged ||
                                                                        0,
                                                                )
                                                            }
                                                            onChange={(v) =>
                                                                setLine(i, {
                                                                    good: v,
                                                                })
                                                            }
                                                            label={`Good ${s?.item}`}
                                                            tone="good"
                                                            big={floor}
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <div className="text-[11px] font-medium tracking-wide text-red-700 uppercase dark:text-red-300">
                                                            Damaged
                                                        </div>
                                                        <Stepper
                                                            value={l.damaged}
                                                            max={
                                                                sent -
                                                                Number(
                                                                    l.good || 0,
                                                                )
                                                            }
                                                            onChange={(v) =>
                                                                setLine(i, {
                                                                    damaged: v,
                                                                })
                                                            }
                                                            label={`Damaged ${s?.item}`}
                                                            tone="damaged"
                                                            big={floor}
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                                <InputError message={form.errors.lines} />
                                <label
                                    className={cn(
                                        'flex items-center gap-3 text-sm',
                                        floor && 'rounded-lg border p-3',
                                    )}
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4"
                                        checked={form.data.wrong_item}
                                        onChange={(e) =>
                                            form.setData(
                                                'wrong_item',
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    Something else came back instead (wrong
                                    item)
                                </label>
                            </div>
                        </Step>

                        {stores.length > 0 && (
                            <Step
                                n={++step}
                                title="Where the good pieces go"
                                hint="Damaged pieces go to that facility's damaged goods store, opened automatically the first time."
                            >
                                <select
                                    id="into_store_id"
                                    aria-label="Store for good pieces"
                                    className={cn(
                                        'border-input bg-background w-full rounded-md border px-3 text-sm shadow-xs',
                                        floor ? 'h-12' : 'h-10',
                                    )}
                                    value={form.data.into_store_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'into_store_id',
                                            e.target.value,
                                        )
                                    }
                                >
                                    {stores.map((s) => (
                                        <option key={s.value} value={s.value}>
                                            {s.label}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={form.errors.into_store_id}
                                />
                            </Step>
                        )}

                        <Step n={++step} title="Details" hint="Optional.">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="return_awb">
                                        AWB on the returning packet
                                    </Label>
                                    <Input
                                        id="return_awb"
                                        value={form.data.return_awb}
                                        onChange={(e) =>
                                            form.setData(
                                                'return_awb',
                                                e.target.value,
                                            )
                                        }
                                        className={cn(floor && 'h-12')}
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label htmlFor="notes">Notes</Label>
                                    <Input
                                        id="notes"
                                        value={form.data.notes}
                                        onChange={(e) =>
                                            form.setData(
                                                'notes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Seal broken, bottle leaking…"
                                        className={cn(floor && 'h-12')}
                                    />
                                </div>
                            </div>
                        </Step>

                        {claimWillOpen && (
                            <p className="flex items-start gap-2 rounded-lg bg-amber-500/15 p-3 text-sm text-amber-900 dark:text-amber-100">
                                <PackageX className="mt-0.5 size-4 shrink-0" />
                                A claim will be opened on the marketplace for
                                what is damaged, missing or wrong. Photograph
                                the packet before opening it further.
                            </p>
                        )}
                    </div>

                    <div
                        className={cn(
                            'bg-muted/40 flex flex-col gap-3 border-t p-4',
                            floor
                                ? 'bg-card/95 sticky bottom-0 z-10 rounded-b-2xl pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-4px_12px_rgba(0,0,0,0.06)] backdrop-blur'
                                : 'sm:flex-row sm:items-center sm:justify-between',
                        )}
                    >
                        <div
                            className={cn(
                                'flex flex-wrap gap-x-4 gap-y-1 text-sm tabular-nums',
                                floor && 'justify-center',
                            )}
                        >
                            <span className="text-emerald-700 dark:text-emerald-300">
                                <b>{totals.good}</b> good
                            </span>
                            <span className="text-red-700 dark:text-red-300">
                                <b>{totals.damaged}</b> damaged
                            </span>
                            <span className="text-amber-700 dark:text-amber-300">
                                <b>{totals.missing}</b> missing
                            </span>
                        </div>
                        <div
                            className={cn(
                                'flex gap-2',
                                floor
                                    ? 'flex-row'
                                    : 'flex-col-reverse sm:flex-row',
                            )}
                        >
                            <Button
                                type="button"
                                variant="outline"
                                className={cn(floor && 'h-12 flex-1')}
                                onClick={onCancel}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className={cn(floor && 'h-12 flex-[2]')}
                                disabled={form.processing || tooMany}
                            >
                                <Undo2 className="size-4" />
                                Receive this return
                            </Button>
                        </div>
                    </div>
                </form>
            )}
        </section>
    );
}

/**
 * The returns this person received lately.
 */
export function RecentReturns({
    recent,
    action,
    floor = false,
}: {
    recent: RecentReturn[];
    action?: ReactNode;
    floor?: boolean;
}) {
    return (
        <section
            className={cn(
                'bg-card overflow-hidden border',
                floor ? 'rounded-2xl' : 'rounded-xl',
            )}
        >
            <div className="flex items-center justify-between gap-3 border-b px-4 py-3">
                <h2 className="text-sm font-semibold">You received recently</h2>
                {action}
            </div>
            {recent.length === 0 ? (
                <p className="text-muted-foreground px-4 py-6 text-center text-sm">
                    Nothing received yet.
                </p>
            ) : (
                <ul className="divide-y text-sm">
                    {recent.map((r) => (
                        <li
                            key={r.id}
                            className="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-3"
                        >
                            <div className="w-36 shrink-0">
                                <div className="font-mono font-medium">
                                    {r.number}
                                </div>
                                <div className="text-muted-foreground text-xs">
                                    {when(r.received_at)}
                                </div>
                            </div>
                            <div
                                className={cn(
                                    'order-last min-w-0 basis-full',
                                    !floor &&
                                        'sm:order-none sm:flex-1 sm:basis-auto',
                                )}
                            >
                                <div className="truncate font-mono text-xs">
                                    {r.awb ?? r.order_number} · {r.kind_label}
                                </div>
                                <div className="text-muted-foreground truncate text-xs">
                                    {r.lines
                                        .map(
                                            (l) =>
                                                `${l.item}: ${Number(l.good)} good` +
                                                (Number(l.damaged) > 0
                                                    ? `, ${Number(l.damaged)} damaged`
                                                    : '') +
                                                (Number(l.missing) > 0
                                                    ? `, ${Number(l.missing)} missing`
                                                    : ''),
                                        )
                                        .join(' · ')}
                                </div>
                            </div>
                            <div className="ml-auto">
                                <StatusBadge
                                    variant={TONE_VARIANT[r.claim_tone]}
                                >
                                    {r.claim_label}
                                </StatusBadge>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
