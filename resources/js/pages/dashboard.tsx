import { Head, Link, router } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';
import {
    OutputChart,
    ReceivingChart,
    StoreCard,
} from '@/components/dashboard/charts';
import { CustomiseMenu } from '@/components/dashboard/customise-menu';
import { Hero } from '@/components/dashboard/hero';
import { KpiTile } from '@/components/dashboard/kpi-tile';
import {
    ActivityList,
    AttentionList,
    ExpiringList,
    ProductionList,
    UpcomingList,
} from '@/components/dashboard/lists';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    useDashboardCards,
    type DashboardCardKey,
} from '@/hooks/use-dashboard-cards';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as auditIndex } from '@/routes/audit';
import { index as lotsIndex } from '@/routes/lots';
import { index as manufacturingIndex } from '@/routes/manufacturing';
import { index as stockIndex } from '@/routes/stock';
import type {
    ActivityEntry,
    AttentionRow,
    ExpiringRow,
    InProductionRow,
    KpiTile as Tile,
    OutputPoint,
    ReceivingPoint,
    StoreLevels,
    UpcomingRow,
} from '@/types';

function Card({
    title,
    description,
    action,
    children,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section className={cn('bg-card rounded-2xl border', className)}>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div>
                    <h2 className="font-semibold">{title}</h2>
                    {description && (
                        <p className="text-muted-foreground text-xs">
                            {description}
                        </p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

type FacilityChip = {
    id: number;
    code: string;
    name: string;
    can_manufacture: boolean;
};

export default function Dashboard({
    greeting,
    headlines,
    kpis,
    period,
    facilities,
    facility,
    stores,
    attention,
    expiring,
    receiving,
    output,
    inProduction,
    upcoming,
    recentActivity,
    quickActions,
}: {
    greeting: {
        name: string;
        first_name: string;
        date: string;
        roles: string[];
    };
    headlines: string[];
    kpis: Tile[];
    period: { days: number };
    facilities: FacilityChip[];
    facility: FacilityChip | null;
    stores: StoreLevels[] | null;
    attention: AttentionRow[] | null;
    expiring: ExpiringRow[] | null;
    receiving: ReceivingPoint[] | null;
    output: OutputPoint[] | null;
    inProduction: InProductionRow[] | null;
    upcoming: UpcomingRow[] | null;
    recentActivity: ActivityEntry[] | null;
    quickActions: {
        plan: boolean;
        receive: boolean;
        qc: boolean;
        formulas: boolean;
    };
}) {
    const cards = useDashboardCards();

    const available = useMemo(() => {
        const keys: DashboardCardKey[] = [];
        if (kpis.length > 0) keys.push('kpis');
        if (stores) keys.push('stores');
        if (inProduction) keys.push('production');
        if (receiving) keys.push('receiving');
        if (output) keys.push('output');
        if (attention) keys.push('attention');
        if (expiring) keys.push('expiring');
        if (upcoming) keys.push('upcoming');
        if (recentActivity) keys.push('activity');
        return keys;
    }, [
        kpis,
        stores,
        inProduction,
        receiving,
        output,
        attention,
        expiring,
        upcoming,
        recentActivity,
    ]);

    const show = (key: DashboardCardKey) =>
        available.includes(key) && cards.visible(key);

    const changePeriod = (days: number) =>
        router.get(
            dashboard().url,
            { days, facility: facility?.id },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['receiving', 'period'],
            },
        );

    const changeFacility = (value: string) =>
        router.get(
            dashboard().url,
            value === 'all'
                ? { days: period.days }
                : { days: period.days, facility: value },
            { preserveState: true, preserveScroll: true },
        );

    const nothing = available.length === 0;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-5 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-sm">
                        {greeting.roles.length > 0
                            ? `Signed in as ${greeting.roles.join(', ')}`
                            : 'No role assigned yet'}
                    </p>
                    <div className="flex flex-wrap items-center gap-2">
                        {facilities.length > 1 && (
                            <Select
                                value={facility ? String(facility.id) : 'all'}
                                onValueChange={changeFacility}
                            >
                                <SelectTrigger className="min-w-48">
                                    <SelectValue placeholder="All facilities" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All facilities
                                    </SelectItem>
                                    {facilities.map((f) => (
                                        <SelectItem
                                            key={f.id}
                                            value={String(f.id)}
                                        >
                                            {f.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {!nothing && (
                            <CustomiseMenu
                                available={available}
                                visible={cards.visible}
                                toggle={cards.toggle}
                                reset={cards.reset}
                            />
                        )}
                    </div>
                </div>

                <Hero
                    firstName={greeting.first_name}
                    date={greeting.date}
                    headlines={headlines}
                    actions={quickActions}
                />

                {nothing && (
                    <div className="bg-card rounded-2xl border p-10 text-center">
                        <p className="font-medium">Nothing assigned yet</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Your account does not yet have access to any ERP
                            module. Ask an administrator to assign you a role.
                        </p>
                    </div>
                )}

                {show('kpis') && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        {kpis.map((tile) => (
                            <KpiTile key={tile.key} tile={tile} />
                        ))}
                    </div>
                )}

                {show('stores') && stores && (
                    <div className="grid gap-4 lg:grid-cols-3">
                        {stores.map((store) => (
                            <StoreCard key={store.kind} store={store} />
                        ))}
                    </div>
                )}

                <div className="grid gap-4 xl:grid-cols-3">
                    {show('production') && inProduction && (
                        <Card
                            title="In production"
                            description="Batches approved or on the floor."
                            className="xl:col-span-1"
                            action={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={manufacturingIndex()}>
                                        All orders
                                    </Link>
                                </Button>
                            }
                        >
                            <ProductionList rows={inProduction} />
                        </Card>
                    )}

                    {show('receiving') && receiving && (
                        <Card
                            title="Receiving & QC"
                            description="Delivery lines booked in, and QC decisions, per day."
                            className={
                                inProduction && show('production')
                                    ? 'xl:col-span-2'
                                    : 'xl:col-span-3'
                            }
                            action={
                                <div className="flex gap-1">
                                    {[7, 30, 90].map((d) => (
                                        <Button
                                            key={d}
                                            size="sm"
                                            variant={
                                                period.days === d
                                                    ? 'default'
                                                    : 'ghost'
                                            }
                                            onClick={() => changePeriod(d)}
                                        >
                                            {d}d
                                        </Button>
                                    ))}
                                </div>
                            }
                        >
                            <div className="px-3 pt-4 pb-2">
                                <ReceivingChart data={receiving} />
                            </div>
                        </Card>
                    )}
                </div>

                <div className="grid gap-4 xl:grid-cols-3">
                    {show('output') && output && (
                        <Card
                            title="Production output"
                            description="Units packed per week, last 12 weeks."
                            className="xl:col-span-2"
                        >
                            <div className="px-3 pt-4 pb-2">
                                <OutputChart data={output} />
                            </div>
                        </Card>
                    )}

                    {show('attention') && attention && (
                        <Card
                            title="Materials to watch"
                            description="Low, critically low or out of stock."
                            action={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={stockIndex()}>Stock</Link>
                                </Button>
                            }
                        >
                            <AttentionList rows={attention} />
                        </Card>
                    )}
                </div>

                <div className="grid gap-4 xl:grid-cols-3">
                    {show('expiring') && expiring && (
                        <Card
                            title="Expiring batches"
                            action={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={lotsIndex()}>Batches</Link>
                                </Button>
                            }
                        >
                            <ExpiringList rows={expiring} />
                        </Card>
                    )}

                    {show('upcoming') && upcoming && (
                        <Card title="Coming up" description="Next two weeks.">
                            <UpcomingList rows={upcoming} />
                        </Card>
                    )}

                    {show('activity') && recentActivity && (
                        <Card
                            title="Recent activity"
                            action={
                                <Button variant="ghost" size="sm" asChild>
                                    <Link href={auditIndex()}>View all</Link>
                                </Button>
                            }
                        >
                            <ActivityList rows={recentActivity} />
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
