import { Head, Link, router, useForm } from '@inertiajs/react';
import { FileText, Truck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Scanner } from '@/components/floor/scanner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { when } from '@/lib/dispatch';
import { courierName, type Parcel } from '@/lib/online-orders';
import { cn } from '@/lib/utils';
import { handover, pack } from '@/routes/floor';
import { store as storeHandover } from '@/routes/floor/handover';

type Sheet = {
    id: number;
    number: string;
    courier: string;
    count: number;
    by: string | null;
    received_by: string | null;
    at: string;
    pdf: string;
};

export default function CourierHandover({
    stores,
    store,
    parcels,
    sheets,
    just_made,
}: {
    stores: { value: string; label: string }[];
    store: number | null;
    parcels: Parcel[];
    sheets: Sheet[];
    just_made: number | null;
}) {
    const couriers = useMemo(() => {
        const map = new Map<string, Parcel[]>();
        parcels.forEach((p) => {
            const key = p.courier ?? '';
            map.set(key, [...(map.get(key) ?? []), p]);
        });

        return Array.from(map.entries());
    }, [parcels]);

    const [courier, setCourier] = useState<string>(couriers[0]?.[0] ?? '');
    const inPile = useMemo(
        () => couriers.find(([c]) => c === courier)?.[1] ?? [],
        [couriers, courier],
    );

    const form = useForm<{
        warehouse_id: number | null;
        courier: string;
        shipment_ids: number[];
        received_by: string;
    }>({
        warehouse_id: store,
        courier,
        shipment_ids: inPile.map((p) => p.id),
        received_by: '',
    });

    // A different courier's pile starts fully ticked.
    useEffect(() => {
        form.setData((d) => ({
            ...d,
            courier,
            shipment_ids: inPile.map((p) => p.id),
        }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [courier, inPile]);

    const justMade = sheets.find((s) => s.id === just_made);

    const toggle = (id: number) =>
        form.setData(
            'shipment_ids',
            form.data.shipment_ids.includes(id)
                ? form.data.shipment_ids.filter((x) => x !== id)
                : [...form.data.shipment_ids, id],
        );

    // Scanning a parcel ticks it (and switches to its courier).
    const onCode = (code: string) => {
        const c = code.trim().toUpperCase();
        const hit = parcels.find(
            (p) =>
                p.awb?.toUpperCase() === c ||
                p.alt_code?.toUpperCase() === c ||
                p.order_number?.toUpperCase() === c,
        );

        if (!hit) {
            toast.error(`${code} is not a packed parcel waiting here.`);
            navigator.vibrate?.([120, 80, 120]);

            return;
        }

        if ((hit.courier ?? '') !== courier) {
            setCourier(hit.courier ?? '');
            toast.info(`${hit.awb} goes with ${courierName(hit.courier)}.`);

            return;
        }

        if (!form.data.shipment_ids.includes(hit.id)) {
            toggle(hit.id);
        }

        toast.success(`${hit.awb} ticked.`);
        navigator.vibrate?.(40);
    };

    return (
        <>
            <Head title="Courier pickup" />
            <div className="space-y-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">
                            Courier pickup
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Tick what the courier takes, then hand over and
                            print the sheet for them to sign.
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={pack()}>Pack</Link>
                    </Button>
                </div>

                {justMade && (
                    <a
                        href={justMade.pdf}
                        target="_blank"
                        rel="noreferrer"
                        className="flex items-center gap-3 rounded-2xl border-2 border-emerald-600 bg-emerald-500/15 p-4"
                    >
                        <FileText className="size-8 text-emerald-600" />
                        <span>
                            <span className="block font-semibold">
                                Print {justMade.number}
                            </span>
                            <span className="text-sm">
                                {justMade.count} parcel(s) to {justMade.courier}{' '}
                                — the courier signs this.
                            </span>
                        </span>
                    </a>
                )}

                {stores.length > 1 && (
                    <select
                        className="border-input bg-background h-11 w-full rounded-md border px-3"
                        value={store ?? ''}
                        onChange={(e) =>
                            router.get(handover().url, {
                                store: e.target.value,
                            })
                        }
                        aria-label="Store"
                    >
                        {stores.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                )}

                {parcels.length === 0 ? (
                    <p className="bg-card text-muted-foreground rounded-2xl border p-6 text-center text-sm">
                        No packed parcels are waiting for a courier.
                    </p>
                ) : (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {couriers.map(([c, list]) => (
                                <button
                                    key={c}
                                    type="button"
                                    onClick={() => setCourier(c)}
                                    className={cn(
                                        'rounded-full border px-4 py-2 text-sm font-medium',
                                        c === courier
                                            ? 'bg-primary text-primary-foreground border-primary'
                                            : 'bg-card',
                                    )}
                                >
                                    {courierName(c || null)} · {list.length}
                                </button>
                            ))}
                        </div>

                        <Scanner
                            onCode={onCode}
                            autoStart={false}
                            placeholder="Scan a parcel to tick it…"
                        />

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(storeHandover().url, {
                                    preserveScroll: true,
                                });
                            }}
                            className="space-y-3"
                        >
                            <ul className="bg-card divide-y rounded-2xl border">
                                {inPile.map((p) => (
                                    <li key={p.id}>
                                        <label className="flex items-center gap-3 px-4 py-3">
                                            <Checkbox
                                                checked={form.data.shipment_ids.includes(
                                                    p.id,
                                                )}
                                                onCheckedChange={() =>
                                                    toggle(p.id)
                                                }
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block font-mono text-sm font-medium">
                                                    {p.awb ?? p.order_number}
                                                </span>
                                                <span className="text-muted-foreground block truncate text-xs">
                                                    {p.marketplace} ·{' '}
                                                    {p.lines
                                                        .map(
                                                            (l) =>
                                                                `${l.item ?? l.seller_sku} × ${l.quantity}`,
                                                        )
                                                        .join(', ')}
                                                </span>
                                            </span>
                                            <span className="text-xs">
                                                {p.payment_label}
                                            </span>
                                        </label>
                                    </li>
                                ))}
                            </ul>

                            <div className="space-y-1">
                                <Label htmlFor="received_by">
                                    Courier&rsquo;s person (optional)
                                </Label>
                                <Input
                                    id="received_by"
                                    value={form.data.received_by}
                                    onChange={(e) =>
                                        form.setData(
                                            'received_by',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Name and phone"
                                />
                            </div>
                            <InputError
                                message={
                                    form.errors.shipment_ids ??
                                    form.errors.courier
                                }
                            />
                            <Button
                                type="submit"
                                className="h-12 w-full"
                                disabled={
                                    form.processing ||
                                    form.data.shipment_ids.length === 0
                                }
                            >
                                <Truck className="size-4" />
                                Hand {form.data.shipment_ids.length} parcel(s)
                                to {courierName(courier || null)}
                            </Button>
                        </form>
                    </>
                )}

                {sheets.length > 0 && (
                    <section className="bg-card rounded-2xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Recent handovers
                        </h2>
                        <ul className="divide-y text-sm">
                            {sheets.map((s) => (
                                <li key={s.id}>
                                    <a
                                        href={s.pdf}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex items-center gap-3 px-4 py-3"
                                    >
                                        <span className="font-mono font-medium">
                                            {s.number}
                                        </span>
                                        <span className="flex-1">
                                            {s.count} to {s.courier}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {when(s.at)}
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
