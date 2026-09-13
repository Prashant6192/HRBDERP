import { Head, Link, router } from '@inertiajs/react';
import { CircleDollarSign } from 'lucide-react';
import {
    MarginBadge,
    type ProfitabilityBatch,
    type ProfitabilityProduct,
    type ProfitabilityTotals,
} from '@/components/contract/client-profitability-panel';
import { StatTile } from '@/components/intelligence/facility-filter';
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
import { rupees } from '@/lib/intelligence';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { profitability, show as showClient } from '@/routes/clients';

type ClientRow = ProfitabilityTotals & {
    client_id: number;
    code: string | null;
    name: string | null;
    batches: number;
    products: ProfitabilityProduct[];
    recent: ProfitabilityBatch[];
};

const PERIODS = [30, 90, 180, 365, 730];

export default function ClientProfitabilityIndex({
    clients,
    filters,
}: {
    clients: ClientRow[];
    filters: { days: number };
}) {
    const sum = (key: keyof ProfitabilityTotals) =>
        clients.reduce((s, c) => s + Number(c[key] ?? 0), 0);
    const revenue = sum('revenue');
    const margin = sum('margin');
    const losing = clients.filter((c) => Number(c.margin) < 0).length;

    return (
        <>
            <Head title="Client profitability" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Client profitability"
                    description="For contract manufacturing: what each client was charged, what their batches cost us in our own raw material, packaging and wastage, and the margin — per client, product and batch."
                    actions={
                        <div className="flex gap-1">
                            {PERIODS.map((d) => (
                                <Button
                                    key={d}
                                    size="sm"
                                    variant={
                                        filters.days === d
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                    onClick={() =>
                                        router.get(
                                            profitability().url,
                                            { days: d },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    {d >= 365 ? `${d / 365} yr` : `${d} d`}
                                </Button>
                            ))}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatTile label="Revenue" value={rupees(revenue)} />
                    <StatTile
                        label="Margin"
                        value={rupees(margin)}
                        tone={margin < 0 ? 'danger' : 'success'}
                        hint={
                            revenue > 0
                                ? `${Math.round((margin / revenue) * 100)}% of revenue`
                                : undefined
                        }
                    />
                    <StatTile label="Clients" value={clients.length} />
                    <StatTile
                        label="Losing money"
                        value={losing}
                        tone={losing > 0 ? 'danger' : 'success'}
                        hint="clients whose batches cost more than they pay"
                    />
                </div>

                <div className="bg-card overflow-x-auto rounded-2xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Client</TableHead>
                                <TableHead className="text-right">
                                    Batches
                                </TableHead>
                                <TableHead className="text-right">
                                    Revenue
                                </TableHead>
                                <TableHead className="text-right">
                                    Mfg charge
                                </TableHead>
                                <TableHead className="text-right">
                                    Raw material
                                </TableHead>
                                <TableHead className="text-right">
                                    Packaging
                                </TableHead>
                                <TableHead className="text-right">
                                    Wastage
                                </TableHead>
                                <TableHead className="text-right">
                                    Margin
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {clients.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={9}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        <CircleDollarSign className="mx-auto mb-2 size-6" />
                                        No client batch completed in this
                                        period.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                clients.map((c) => (
                                    <TableRow key={c.client_id}>
                                        <TableCell>
                                            <Link
                                                href={showClient(c.client_id)}
                                                className="font-medium underline-offset-4 hover:underline"
                                            >
                                                {c.name}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">
                                                {c.code} ·{' '}
                                                {c.products
                                                    .map((p) => p.product)
                                                    .filter(Boolean)
                                                    .slice(0, 3)
                                                    .join(', ')}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {c.batches}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupees(c.revenue)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupees(c.manufacturing_charge)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupees(c.raw_material_cost)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupees(c.packaging_cost)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {rupees(c.wastage_cost)}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-semibold tabular-nums',
                                                Number(c.margin) < 0 &&
                                                    'text-red-700 dark:text-red-300',
                                            )}
                                        >
                                            {rupees(c.margin)}
                                        </TableCell>
                                        <TableCell>
                                            <MarginBadge
                                                percent={c.margin_percent}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

ClientProfitabilityIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Client profitability', href: profitability() },
    ],
};
