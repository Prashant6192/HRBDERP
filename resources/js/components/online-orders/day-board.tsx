import { Link } from '@inertiajs/react';
import { ScanBarcode } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type DayProduct = {
    item_id: number;
    name: string;
    size: string | null;
    pieces: number;
    to_pack: number;
    orders: number;
    short: number;
    has_barcode: boolean;
    photo: string | null;
};

type StepState = 'done' | 'now' | 'ahead';

/**
 * One step of the day: uploaded, printed, picked, packed, handed over.
 * The step the depot is on is outlined and carries the button for it.
 */
export function DayStep({
    number,
    label,
    value,
    hint,
    state,
    onClick,
    children,
}: {
    number: number;
    label: string;
    value: string;
    hint?: string;
    state: StepState;
    /** Show this step's parcels in the list below. */
    onClick?: () => void;
    children?: ReactNode;
}) {
    const body = (
        <>
            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
                {number} · {label}
            </p>
            <p
                className={cn(
                    'mt-1.5 text-3xl leading-tight font-bold tabular-nums',
                    state === 'done' &&
                        'text-emerald-700 dark:text-emerald-300',
                )}
            >
                {value}
            </p>
            {hint && (
                <p className="text-muted-foreground mt-0.5 text-xs">{hint}</p>
            )}
        </>
    );

    return (
        <div
            className={cn(
                'bg-card flex min-w-0 flex-col rounded-xl border p-4 transition',
                state === 'done' && 'border-emerald-600/50',
                state === 'now' && 'border-primary ring-primary/20 ring-4',
            )}
        >
            {onClick ? (
                <button type="button" onClick={onClick} className="text-left">
                    {body}
                </button>
            ) : (
                <div>{body}</div>
            )}
            {children && (
                <div className="mt-3 flex flex-wrap gap-2">{children}</div>
            )}
        </div>
    );
}

/**
 * The day's products to take off the racks, most pieces first, with each
 * one's photo: what the packers will be asked for.
 */
export function DayProducts({
    products,
    pickListUrl,
}: {
    products: DayProduct[];
    pickListUrl: string;
}) {
    const pieces = products.reduce((n, p) => n + p.pieces, 0);

    return (
        <section className="bg-card rounded-xl border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                <h2 className="text-sm font-semibold">
                    Pick list by product
                    <span className="text-muted-foreground ml-2 font-normal">
                        {pieces} pieces · {products.length}{' '}
                        {products.length === 1 ? 'product' : 'products'}
                    </span>
                </h2>
                <Link
                    href={pickListUrl}
                    className="text-primary text-sm font-medium underline-offset-2 hover:underline"
                >
                    Open pick list
                </Link>
            </div>
            {products.length === 0 ? (
                <p className="text-muted-foreground px-4 py-6 text-sm">
                    No products yet: upload the day&rsquo;s labels and map their
                    SKUs.
                </p>
            ) : (
                <ul className="divide-y">
                    {products.map((p) => (
                        <li
                            key={p.item_id}
                            className="grid grid-cols-[3rem_minmax(0,1fr)_auto] items-center gap-3 px-4 py-2.5"
                        >
                            {p.photo ? (
                                <img
                                    src={p.photo}
                                    alt=""
                                    className="size-12 rounded-lg border bg-white object-contain"
                                />
                            ) : (
                                <div className="bg-muted size-12 rounded-lg border" />
                            )}
                            <div className="min-w-0">
                                <p className="leading-tight font-semibold">
                                    {p.name}
                                    {p.size && (
                                        <span className="bg-primary text-primary-foreground ml-2 rounded-md px-1.5 py-0.5 text-xs font-bold whitespace-nowrap">
                                            {p.size}
                                        </span>
                                    )}
                                </p>
                                <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                    <span>
                                        {p.orders}{' '}
                                        {p.orders === 1 ? 'order' : 'orders'}
                                    </span>
                                    {p.to_pack < p.pieces && (
                                        <span>
                                            {p.to_pack === 0
                                                ? 'all packed'
                                                : `${p.to_pack} still to pack`}
                                        </span>
                                    )}
                                    {p.short > 0 && (
                                        <span className="rounded-full bg-red-500/15 px-2 py-0.5 font-semibold text-red-700 dark:text-red-300">
                                            {p.short} short
                                        </span>
                                    )}
                                    {!p.has_barcode && (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-400/25 px-2 py-0.5 font-semibold text-amber-800 dark:text-amber-200">
                                            <ScanBarcode className="size-3" />
                                            No barcode
                                        </span>
                                    )}
                                </p>
                            </div>
                            <div className="text-right">
                                <p className="text-2xl leading-none font-bold tabular-nums">
                                    {p.pieces}
                                </p>
                                <p className="text-muted-foreground text-xs">
                                    {p.pieces === 1 ? 'piece' : 'pieces'}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** The day's parcels by the platform they came from. */
export function DayPlatforms({
    platforms,
}: {
    platforms: { name: string; parcels: number }[];
}) {
    return (
        <section className="bg-card rounded-xl border">
            <h2 className="border-b px-4 py-3 text-sm font-semibold">
                By platform
            </h2>
            <div className="flex flex-wrap gap-2 p-4">
                {platforms.map((p) => (
                    <div
                        key={p.name}
                        className="bg-background min-w-24 flex-1 rounded-lg border px-3 py-2"
                    >
                        <p className="text-2xl leading-tight font-bold tabular-nums">
                            {p.parcels}
                        </p>
                        <p className="text-muted-foreground text-xs font-semibold">
                            {p.name}
                        </p>
                    </div>
                ))}
            </div>
        </section>
    );
}
