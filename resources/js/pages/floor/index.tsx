import { Head, Link } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Camera,
    ClipboardCheck,
    ClipboardList,
    Factory,
    Gauge,
    PackageCheck,
    PackagePlus,
    ScanLine,
    Truck,
    type LucideIcon,
} from 'lucide-react';
import { create as createReceipt } from '@/routes/goods-receipts';
import { index as countsIndex } from '@/routes/counts';
import { handover, issue, pack, production, scan } from '@/routes/floor';
import { index as qcIndex } from '@/routes/qc';
import { index as transfersIndex } from '@/routes/transfers';

type Running = {
    id: number;
    number: string;
    product: string | null;
    status: string;
    stage: string | null;
    progress: number;
};

function Tile({
    href,
    icon: Icon,
    label,
    hint,
    tone = 'default',
}: {
    href: string;
    icon: LucideIcon;
    label: string;
    hint: string;
    tone?: 'default' | 'primary';
}) {
    return (
        <Link
            href={href}
            className={
                tone === 'primary'
                    ? 'bg-primary text-primary-foreground flex flex-col gap-2 rounded-2xl p-4 active:opacity-90'
                    : 'bg-card active:bg-muted flex flex-col gap-2 rounded-2xl border p-4'
            }
        >
            <Icon className="size-7" />
            <span className="text-base leading-tight font-semibold">
                {label}
            </span>
            <span
                className={
                    tone === 'primary'
                        ? 'text-primary-foreground/80 text-xs'
                        : 'text-muted-foreground text-xs'
                }
            >
                {hint}
            </span>
        </Link>
    );
}

export default function FloorHome({
    running,
    counts,
    can,
}: {
    running: Running[];
    counts: { id: number; number: string; store: string | null }[];
    can: Record<
        | 'receive'
        | 'issue'
        | 'qc'
        | 'count'
        | 'transfer'
        | 'production'
        | 'photo'
        | 'pack'
        | 'handover',
        boolean
    >;
}) {
    return (
        <>
            <Head title="Floor" />
            <div className="space-y-5">
                <div className="grid grid-cols-2 gap-3">
                    <Tile
                        href={scan().url}
                        icon={ScanLine}
                        label="Scan"
                        hint="A batch, an order, a rack"
                        tone="primary"
                    />
                    {can.receive && (
                        <Tile
                            href={createReceipt().url}
                            icon={PackagePlus}
                            label="Receive"
                            hint="Book a delivery in"
                        />
                    )}
                    {can.issue && (
                        <Tile
                            href={
                                running[0]
                                    ? issue(running[0].id).url
                                    : scan().url
                            }
                            icon={Factory}
                            label="Issue"
                            hint="Scan drums against a batch"
                        />
                    )}
                    {can.qc && (
                        <Tile
                            href={qcIndex().url}
                            icon={ClipboardCheck}
                            label="Approve"
                            hint="QC checkpoint"
                        />
                    )}
                    {can.count && (
                        <Tile
                            href={countsIndex().url}
                            icon={ClipboardList}
                            label="Count"
                            hint="Shelf against system"
                        />
                    )}
                    {can.transfer && (
                        <Tile
                            href={transfersIndex().url}
                            icon={ArrowLeftRight}
                            label="Transfer"
                            hint="Between facilities"
                        />
                    )}
                    {can.production && (
                        <Tile
                            href={production().url}
                            icon={Gauge}
                            label="Record production"
                            hint="Stage and progress"
                        />
                    )}
                    {can.pack && (
                        <Tile
                            href={pack().url}
                            icon={PackageCheck}
                            label="Pack parcels"
                            hint="Scan each online order's label"
                        />
                    )}
                    {can.handover && (
                        <Tile
                            href={handover().url}
                            icon={Truck}
                            label="Courier pickup"
                            hint="Hand packed parcels over"
                        />
                    )}
                    {can.photo && (
                        <Tile
                            href={scan().url}
                            icon={Camera}
                            label="Photo"
                            hint="Scan first, then attach"
                        />
                    )}
                </div>

                {running.length > 0 && (
                    <section className="bg-card rounded-2xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            On the floor
                        </h2>
                        <ul className="divide-y">
                            {running.map((o) => (
                                <li key={o.id}>
                                    <Link
                                        href={can.issue ? issue(o.id) : scan()}
                                        className="active:bg-muted flex items-center justify-between gap-3 px-4 py-3"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {o.number}
                                            </span>
                                            <span className="text-muted-foreground block text-xs">
                                                {o.product ?? '—'} ·{' '}
                                                {o.status === 'in_progress'
                                                    ? `${o.stage ?? 'running'} ${o.progress}%`
                                                    : 'ready to start'}
                                            </span>
                                        </span>
                                        <span className="bg-muted h-1.5 w-16 overflow-hidden rounded-full">
                                            <span
                                                className="bg-primary block h-full"
                                                style={{
                                                    width: `${o.status === 'in_progress' ? o.progress : 0}%`,
                                                }}
                                            />
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {counts.length > 0 && (
                    <section className="bg-card rounded-2xl border">
                        <h2 className="border-b px-4 py-3 text-sm font-semibold">
                            Counts in progress
                        </h2>
                        <ul className="divide-y">
                            {counts.map((c) => (
                                <li key={c.id}>
                                    <Link
                                        href={`/counts/${c.id}`}
                                        className="active:bg-muted flex items-center justify-between px-4 py-3"
                                    >
                                        <span className="font-medium">
                                            {c.number}
                                        </span>
                                        <span className="text-muted-foreground text-xs">
                                            {c.store}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
