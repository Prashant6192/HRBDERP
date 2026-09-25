import { Head, Link, useForm } from '@inertiajs/react';
import { PackageX, ScanLine, Search, Undo2 } from 'lucide-react';
import {
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TONE_VARIANT, when } from '@/lib/dispatch';
import { courierName, describeParcel, type Parcel } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as onlineOrders } from '@/routes/online-orders';
import {
    create as receiveRoute,
    index as returnsIndex,
    lookup,
    store,
} from '@/routes/online-orders/returns';

type Sent = {
    item_id: number;
    item: string;
    item_code: string;
    units: string;
};

type Found = {
    shipment: Parcel;
    sent: Sent[];
    why_not: string | null;
    store_id: number;
};

type RecentReturn = {
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

type Line = { item_id: number; good: string; damaged: string };

const KIND_HINT: Record<string, string> = {
    rto: 'The courier brought it back undelivered.',
    customer: 'The buyer received it and sent it back.',
};

function Step({
    n,
    title,
    hint,
    aside,
    children,
}: {
    n: number;
    title: string;
    hint?: string;
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-start gap-3">
                <span className="bg-primary/10 text-primary flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                    {n}
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="text-sm font-semibold">{title}</h3>
                    {hint && (
                        <p className="text-muted-foreground text-xs">{hint}</p>
                    )}
                </div>
                {aside}
            </div>
            <div className="sm:pl-9">{children}</div>
        </div>
    );
}

function Count({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('space-y-1', className)}>
            <div className="text-muted-foreground text-[11px] font-medium tracking-wide uppercase">
                {label}
            </div>
            {children}
        </div>
    );
}

export default function ReceiveReturn({
    code: presetCode,
    kinds,
    stores,
    recent,
}: {
    code: string;
    kinds: { value: string; label: string }[];
    stores: { value: string; label: string }[];
    recent: RecentReturn[];
}) {
    const [code, setCode] = useState(presetCode);
    const [finding, setFinding] = useState(false);
    const [problem, setProblem] = useState<string | null>(null);
    const [found, setFound] = useState<Found | null>(null);
    const codeInput = useRef<HTMLInputElement>(null);

    const form = useForm<{
        shipment_id: number | null;
        kind: string;
        into_store_id: string;
        lines: Line[];
        wrong_item: boolean;
        return_awb: string;
        notes: string;
    }>({
        shipment_id: null,
        kind: 'rto',
        into_store_id: '',
        lines: [],
        wrong_item: false,
        return_awb: '',
        notes: '',
    });

    const find = async (value: string) => {
        if (value.trim() === '') {
            return;
        }

        setFinding(true);
        setProblem(null);
        setFound(null);

        try {
            const response = await fetch(
                lookup({ query: { code: value.trim() } }).url,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );
            const data = (await response.json()) as Found & {
                message?: string;
            };

            if (!response.ok || !data.shipment) {
                setProblem(data.message ?? 'No parcel has that code.');

                return;
            }

            setFound(data);
            const inList = stores.some(
                (s) => s.value === String(data.store_id),
            );
            form.clearErrors();
            form.setData({
                shipment_id: data.shipment.id,
                kind: 'rto',
                into_store_id: inList
                    ? String(data.store_id)
                    : (stores[0]?.value ?? ''),
                lines: data.sent.map((s) => ({
                    item_id: s.item_id,
                    good: s.units,
                    damaged: '0',
                })),
                wrong_item: false,
                return_awb: '',
                notes: '',
            });
        } catch {
            setProblem('Could not reach the server. Try again.');
        } finally {
            setFinding(false);
        }
    };

    useEffect(() => {
        if (presetCode) {
            void find(presetCode);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const onFind = (e: FormEvent) => {
        e.preventDefault();
        void find(code);
    };

    const startOver = () => {
        setFound(null);
        setProblem(null);
        setCode('');
        form.reset();
        form.clearErrors();
        requestAnimationFrame(() => codeInput.current?.focus());
    };

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    const sentFor = (l: Line) =>
        Number(found?.sent.find((s) => s.item_id === l.item_id)?.units ?? 0);

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

    const missingFor = (l: Line) =>
        sentFor(l) - Number(l.good || 0) - Number(l.damaged || 0);

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
            preserveScroll: true,
            onSuccess: startOver,
        });
    };

    const p = found?.shipment;

    return (
        <>
            <Head title="Receive a return" />
            <div className="mx-auto w-full max-w-3xl min-w-0 space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Receive a return"
                    description="Scan the label of the parcel that came back, count what is good and what is damaged, and receive it."
                />

                <section className="bg-card rounded-xl border p-4">
                    <form onSubmit={onFind} className="space-y-2">
                        <Label htmlFor="code">Find the parcel</Label>
                        <div className="flex gap-2">
                            <div className="relative min-w-0 flex-1">
                                <ScanLine className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2" />
                                <Input
                                    id="code"
                                    ref={codeInput}
                                    value={code}
                                    onChange={(e) => setCode(e.target.value)}
                                    placeholder="Scan the label, or type the AWB or order number"
                                    autoFocus
                                    autoComplete="off"
                                    className="h-12 pl-10 text-base"
                                />
                            </div>
                            <Button
                                type="submit"
                                className="h-12 px-5"
                                disabled={finding || code.trim() === ''}
                            >
                                <Search className="size-4" />
                                <span className="hidden sm:inline">
                                    {finding ? 'Finding…' : 'Find'}
                                </span>
                            </Button>
                        </div>
                        {problem && (
                            <p className="rounded-lg border border-red-600/30 bg-red-500/10 p-3 text-sm text-red-800 dark:text-red-200">
                                {problem}
                            </p>
                        )}
                    </form>
                </section>

                {p && found && (
                    <section className="bg-card overflow-hidden rounded-xl border">
                        <div className="bg-muted/40 flex flex-wrap items-start justify-between gap-3 border-b p-4">
                            <div className="min-w-0">
                                <p className="font-mono text-lg font-semibold break-all">
                                    {p.awb ?? p.order_number}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {[
                                        p.brand,
                                        p.marketplace,
                                        courierName(p.courier),
                                    ]
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
                                <Button variant="outline" onClick={startOver}>
                                    Scan another parcel
                                </Button>
                            </div>
                        ) : (
                            <form onSubmit={submit}>
                                <div className="space-y-6 p-4">
                                    <Step n={1} title="Why did it come back?">
                                        <div
                                            role="radiogroup"
                                            className="grid gap-2 sm:grid-cols-2"
                                        >
                                            {kinds.map((k) => {
                                                const on =
                                                    form.data.kind === k.value;

                                                return (
                                                    <button
                                                        key={k.value}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={on}
                                                        onClick={() =>
                                                            form.setData(
                                                                'kind',
                                                                k.value,
                                                            )
                                                        }
                                                        className={cn(
                                                            'flex items-start gap-3 rounded-lg border p-3 text-left',
                                                            on
                                                                ? 'border-primary bg-primary/5 ring-primary/30 ring-1'
                                                                : 'hover:bg-muted/60',
                                                        )}
                                                    >
                                                        <span
                                                            className={cn(
                                                                'mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border',
                                                                on &&
                                                                    'border-primary',
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
                                                            {KIND_HINT[
                                                                k.value
                                                            ] && (
                                                                <span className="text-muted-foreground block text-xs">
                                                                    {
                                                                        KIND_HINT[
                                                                            k
                                                                                .value
                                                                        ]
                                                                    }
                                                                </span>
                                                            )}
                                                        </span>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </Step>

                                    <Step
                                        n={2}
                                        title="Count what came back"
                                        hint="Anything not counted as good or damaged is recorded as not received."
                                    >
                                        <div className="space-y-3">
                                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                                <span className="text-muted-foreground">
                                                    Quick fill:
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        allAs('good')
                                                    }
                                                >
                                                    All good
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        allAs('damaged')
                                                    }
                                                >
                                                    All damaged
                                                </Button>
                                            </div>

                                            <div className="divide-y rounded-lg border">
                                                {form.data.lines.map((l, i) => {
                                                    const s = found.sent.find(
                                                        (x) =>
                                                            x.item_id ===
                                                            l.item_id,
                                                    );
                                                    const missing =
                                                        missingFor(l);

                                                    return (
                                                        <div
                                                            key={l.item_id}
                                                            className="flex flex-col gap-3 p-3 md:flex-row md:items-end"
                                                        >
                                                            <div className="min-w-0 md:flex-1 md:self-center">
                                                                <div className="font-medium">
                                                                    {s?.item}
                                                                </div>
                                                                <div className="text-muted-foreground font-mono text-xs">
                                                                    {
                                                                        s?.item_code
                                                                    }
                                                                </div>
                                                            </div>
                                                            <div className="grid grid-cols-4 gap-2 md:w-80 md:shrink-0">
                                                                <Count label="Sent">
                                                                    <div className="flex h-9 items-center text-base font-semibold tabular-nums">
                                                                        {sentFor(
                                                                            l,
                                                                        )}
                                                                    </div>
                                                                </Count>
                                                                <Count label="Good">
                                                                    <Input
                                                                        type="number"
                                                                        inputMode="numeric"
                                                                        min={0}
                                                                        step="any"
                                                                        value={
                                                                            l.good
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setLine(
                                                                                i,
                                                                                {
                                                                                    good: e
                                                                                        .target
                                                                                        .value,
                                                                                },
                                                                            )
                                                                        }
                                                                        className="h-9 border-emerald-600/40 tabular-nums"
                                                                        aria-label={`Good ${s?.item}`}
                                                                    />
                                                                </Count>
                                                                <Count label="Damaged">
                                                                    <Input
                                                                        type="number"
                                                                        inputMode="numeric"
                                                                        min={0}
                                                                        step="any"
                                                                        value={
                                                                            l.damaged
                                                                        }
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setLine(
                                                                                i,
                                                                                {
                                                                                    damaged:
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                },
                                                                            )
                                                                        }
                                                                        className="h-9 border-red-600/40 tabular-nums"
                                                                        aria-label={`Damaged ${s?.item}`}
                                                                    />
                                                                </Count>
                                                                <Count label="Missing">
                                                                    <div
                                                                        className={cn(
                                                                            'flex h-9 items-center text-base font-semibold tabular-nums',
                                                                            missing <
                                                                                0 &&
                                                                                'text-sm text-red-700 dark:text-red-300',
                                                                            missing >
                                                                                0 &&
                                                                                'text-amber-700 dark:text-amber-300',
                                                                        )}
                                                                    >
                                                                        {missing <
                                                                        0
                                                                            ? 'Too many'
                                                                            : missing}
                                                                    </div>
                                                                </Count>
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                            <InputError
                                                message={
                                                    form.errors.lines as string
                                                }
                                            />
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="size-4"
                                                    checked={
                                                        form.data.wrong_item
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'wrong_item',
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                                Something else came back instead
                                                (wrong item)
                                            </label>
                                        </div>
                                    </Step>

                                    {stores.length > 0 && (
                                        <Step
                                            n={3}
                                            title="Where the good pieces go"
                                            hint="Damaged pieces go to that facility's damaged goods store, opened automatically the first time."
                                        >
                                            <select
                                                id="into_store_id"
                                                aria-label="Store for good pieces"
                                                className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm shadow-xs"
                                                value={form.data.into_store_id}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'into_store_id',
                                                        e.target.value,
                                                    )
                                                }
                                            >
                                                {stores.map((s) => (
                                                    <option
                                                        key={s.value}
                                                        value={s.value}
                                                    >
                                                        {s.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={
                                                    form.errors.into_store_id
                                                }
                                            />
                                        </Step>
                                    )}

                                    <Step
                                        n={stores.length > 0 ? 4 : 3}
                                        title="Details"
                                        hint="Optional."
                                    >
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
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label htmlFor="notes">
                                                    Notes
                                                </Label>
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
                                                />
                                            </div>
                                        </div>
                                    </Step>

                                    {claimWillOpen && (
                                        <p className="flex items-start gap-2 rounded-lg bg-amber-500/15 p-3 text-sm text-amber-900 dark:text-amber-100">
                                            <PackageX className="mt-0.5 size-4 shrink-0" />
                                            A claim will be opened on the
                                            marketplace for what is damaged,
                                            missing or wrong. Photograph the
                                            packet before opening it further.
                                        </p>
                                    )}
                                </div>

                                <div className="bg-muted/40 flex flex-col gap-3 border-t p-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm tabular-nums">
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
                                    <div className="flex flex-col-reverse gap-2 sm:flex-row">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={startOver}
                                        >
                                            Cancel
                                        </Button>
                                        <Button
                                            type="submit"
                                            disabled={
                                                form.processing || tooMany
                                            }
                                        >
                                            <Undo2 className="size-4" />
                                            Receive this return
                                        </Button>
                                    </div>
                                </div>
                            </form>
                        )}
                    </section>
                )}

                <section className="bg-card overflow-hidden rounded-xl border">
                    <div className="flex items-center justify-between gap-3 border-b px-4 py-3">
                        <h2 className="text-sm font-semibold">
                            You received recently
                        </h2>
                        <Link
                            href={returnsIndex()}
                            className="text-primary text-sm font-medium underline-offset-4 hover:underline"
                        >
                            All returns
                        </Link>
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
                                    <div className="order-last min-w-0 basis-full sm:order-none sm:flex-1 sm:basis-auto">
                                        <div className="truncate font-mono text-xs">
                                            {r.awb ?? r.order_number} ·{' '}
                                            {r.kind_label}
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
            </div>
        </>
    );
}

ReceiveReturn.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Online orders', href: onlineOrders() },
        { title: 'Returns', href: returnsIndex() },
        { title: 'Receive a return', href: receiveRoute() },
    ],
};
