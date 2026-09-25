import { Head, Link, useForm } from '@inertiajs/react';
import { PackageX, Search, Undo2 } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { TONE_VARIANT, when } from '@/lib/dispatch';
import { courierName, describeParcel, type Parcel } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import {
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

    const setLine = (i: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            form.data.lines.map((l, j) => (j === i ? { ...l, ...patch } : l)),
        );

    const allAs = (as: 'good' | 'damaged') =>
        form.setData(
            'lines',
            form.data.lines.map((l) => {
                const units =
                    found?.sent.find((s) => s.item_id === l.item_id)?.units ??
                    '0';

                return as === 'good'
                    ? { ...l, good: units, damaged: '0' }
                    : { ...l, good: '0', damaged: units };
            }),
        );

    const missingFor = (l: Line) => {
        const sent = Number(
            found?.sent.find((s) => s.item_id === l.item_id)?.units ?? 0,
        );

        return sent - Number(l.good || 0) - Number(l.damaged || 0);
    };

    const tooMany = form.data.lines.some((l) => missingFor(l) < 0);
    const anyMissingOrDamaged = form.data.lines.some(
        (l) => missingFor(l) > 0 || Number(l.damaged || 0) > 0,
    );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url, {
            preserveScroll: true,
            onSuccess: () => {
                setFound(null);
                setCode('');
                form.reset();
                codeInput.current?.focus();
            },
        });
    };

    const p = found?.shipment;

    return (
        <>
            <Head title="Receive a return" />
            <div className="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Receive a return"
                    description="A parcel that went out and came back. Scan its label, count what came back good and what came back damaged. Good goods go back on the shelf in the batch they left from; damaged goods go to the damaged goods store."
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={returnsIndex()}>All returns</Link>
                        </Button>
                    }
                />

                <form onSubmit={onFind} className="flex gap-2">
                    <Input
                        ref={codeInput}
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                        placeholder="Scan the label, or type the AWB or order number"
                        autoFocus
                        className="h-12 text-base"
                        aria-label="AWB or order number"
                    />
                    <Button
                        type="submit"
                        className="h-12"
                        disabled={finding || code.trim() === ''}
                    >
                        <Search className="size-4" />
                        Find
                    </Button>
                </form>

                {problem && (
                    <p className="rounded-xl border border-red-600/30 bg-red-500/10 p-4 text-sm">
                        {problem}
                    </p>
                )}

                {p && found && (
                    <section className="bg-card space-y-4 rounded-xl border p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div>
                                <p className="font-mono text-lg font-semibold">
                                    {p.awb ?? p.order_number}
                                </p>
                                <p className="text-muted-foreground text-sm">
                                    {p.brand} · {p.marketplace} ·{' '}
                                    {courierName(p.courier)} · order{' '}
                                    {p.order_number ?? '—'}
                                </p>
                                <p className="text-sm">{describeParcel(p)}</p>
                            </div>
                            <StatusBadge variant={TONE_VARIANT[p.status_tone]}>
                                {p.status_label}
                            </StatusBadge>
                        </div>

                        {found.why_not ? (
                            <p className="rounded-lg bg-amber-500/15 p-3 text-sm font-medium text-amber-900 dark:text-amber-100">
                                {found.why_not}
                            </p>
                        ) : (
                            <form onSubmit={submit} className="space-y-5">
                                <div className="space-y-2">
                                    <Label>Why it came back</Label>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {kinds.map((k) => (
                                            <button
                                                key={k.value}
                                                type="button"
                                                onClick={() =>
                                                    form.setData(
                                                        'kind',
                                                        k.value,
                                                    )
                                                }
                                                className={cn(
                                                    'rounded-lg border p-3 text-left text-sm',
                                                    form.data.kind === k.value
                                                        ? 'border-primary bg-primary/10 font-semibold'
                                                        : 'hover:bg-muted',
                                                )}
                                            >
                                                {k.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <Label>What came back</Label>
                                        <div className="flex gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => allAs('good')}
                                            >
                                                All good
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => allAs('damaged')}
                                            >
                                                All damaged
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="overflow-x-auto rounded-lg border">
                                        <table className="w-full min-w-[34rem] text-sm">
                                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs uppercase">
                                                <tr>
                                                    <th className="px-3 py-2 font-medium">
                                                        Product
                                                    </th>
                                                    <th className="px-3 py-2 text-right font-medium">
                                                        Left
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Good
                                                    </th>
                                                    <th className="px-3 py-2 font-medium">
                                                        Damaged
                                                    </th>
                                                    <th className="px-3 py-2 text-right font-medium">
                                                        Not received
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {form.data.lines.map((l, i) => {
                                                    const s = found.sent.find(
                                                        (x) =>
                                                            x.item_id ===
                                                            l.item_id,
                                                    );
                                                    const missing =
                                                        missingFor(l);

                                                    return (
                                                        <tr key={l.item_id}>
                                                            <td className="px-3 py-2">
                                                                <div className="font-medium">
                                                                    {s?.item}
                                                                </div>
                                                                <div className="text-muted-foreground font-mono text-xs">
                                                                    {
                                                                        s?.item_code
                                                                    }
                                                                </div>
                                                            </td>
                                                            <td className="px-3 py-2 text-right tabular-nums">
                                                                {Number(
                                                                    s?.units ??
                                                                        0,
                                                                )}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Input
                                                                    type="number"
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
                                                                    className="w-24"
                                                                    aria-label={`Good ${s?.item}`}
                                                                />
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Input
                                                                    type="number"
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
                                                                    className="w-24"
                                                                    aria-label={`Damaged ${s?.item}`}
                                                                />
                                                            </td>
                                                            <td
                                                                className={cn(
                                                                    'px-3 py-2 text-right font-semibold tabular-nums',
                                                                    missing <
                                                                        0 &&
                                                                        'text-red-700 dark:text-red-300',
                                                                    missing >
                                                                        0 &&
                                                                        'text-amber-700 dark:text-amber-300',
                                                                )}
                                                            >
                                                                {missing < 0
                                                                    ? 'Too many'
                                                                    : missing}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                {stores.length > 0 && (
                                    <div className="space-y-1">
                                        <Label htmlFor="into_store_id">
                                            Put good goods back into
                                        </Label>
                                        <select
                                            id="into_store_id"
                                            className="border-input bg-background h-10 w-full rounded-md border px-3 text-sm"
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
                                        <p className="text-muted-foreground text-xs">
                                            Damaged goods go to that
                                            facility&rsquo;s damaged goods
                                            store, opened automatically the
                                            first time.
                                        </p>
                                    </div>
                                )}

                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
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

                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="space-y-1">
                                        <Label htmlFor="return_awb">
                                            AWB on the returning packet
                                            (optional)
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
                                    <div className="space-y-1">
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
                                        />
                                    </div>
                                </div>

                                {(anyMissingOrDamaged ||
                                    form.data.wrong_item) && (
                                    <p className="flex items-start gap-2 rounded-lg bg-amber-500/15 p-3 text-sm text-amber-900 dark:text-amber-100">
                                        <PackageX className="mt-0.5 size-4 shrink-0" />
                                        A claim will be opened on the
                                        marketplace for what is damaged, not
                                        received or wrong. Photograph the packet
                                        before you open it further.
                                    </p>
                                )}

                                <Button
                                    type="submit"
                                    className="h-12 w-full"
                                    disabled={form.processing || tooMany}
                                >
                                    <Undo2 className="size-4" />
                                    Receive this return
                                </Button>
                            </form>
                        )}
                    </section>
                )}

                {recent.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            You received recently
                        </h2>
                        <ul className="divide-y text-sm">
                            {recent.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex flex-wrap items-center gap-3 px-4 py-3"
                                >
                                    <span className="font-mono font-medium">
                                        {r.number}
                                    </span>
                                    <span className="font-mono text-xs">
                                        {r.awb ?? r.order_number}
                                    </span>
                                    <span className="text-muted-foreground flex-1 truncate">
                                        {r.lines
                                            .map(
                                                (l) =>
                                                    `${l.item}: ${Number(l.good)} good` +
                                                    (Number(l.damaged) > 0
                                                        ? `, ${Number(l.damaged)} damaged`
                                                        : '') +
                                                    (Number(l.missing) > 0
                                                        ? `, ${Number(l.missing)} not received`
                                                        : ''),
                                            )
                                            .join(' · ')}
                                    </span>
                                    <StatusBadge
                                        variant={TONE_VARIANT[r.claim_tone]}
                                    >
                                        {r.claim_label}
                                    </StatusBadge>
                                    <span className="text-muted-foreground text-xs">
                                        {when(r.received_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
