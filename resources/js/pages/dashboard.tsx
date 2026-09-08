import { Head, Link } from '@inertiajs/react';
import {
    Boxes,
    FlaskConical,
    Package,
    ScrollText,
    Truck,
    Users,
    Warehouse,
    type LucideIcon,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { usePermissions } from '@/hooks/use-permissions';
import { index as auditIndex } from '@/routes/audit';
import { dashboard } from '@/routes';
import type { AuditEntry } from '@/types';

/** Icon names the server sends, resolved to components here. */
const ICONS: Record<string, LucideIcon> = {
    'flask-conical': FlaskConical,
    package: Package,
    boxes: Boxes,
    warehouse: Warehouse,
    truck: Truck,
    users: Users,
};

type Stat = {
    label: string;
    value: number;
    icon: string;
    route: string;
};

/** Wayfinder route names, so a tile can link to its module. */
const ROUTE_URLS: Record<string, string> = {
    'raw-materials.index': '/raw-materials',
    'packaging-materials.index': '/packaging-materials',
    'products.index': '/products',
    'warehouses.index': '/warehouses',
    'vendors.index': '/vendors',
    'users.index': '/users',
};

function StatCard({ stat }: { stat: Stat }) {
    const Icon = ICONS[stat.icon] ?? Boxes;
    const href = ROUTE_URLS[stat.route];

    const card = (
        <div className="bg-card hover:border-primary/40 group rounded-xl border p-5 transition-colors">
            <div className="flex items-start justify-between">
                <p className="text-muted-foreground text-sm font-medium">
                    {stat.label}
                </p>
                <span className="bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary flex size-9 items-center justify-center rounded-lg transition-colors">
                    <Icon className="size-4" />
                </span>
            </div>
            <p className="mt-3 text-3xl font-semibold tracking-tight tabular-nums">
                {stat.value.toLocaleString()}
            </p>
        </div>
    );

    return href ? <Link href={href}>{card}</Link> : card;
}

export default function Dashboard({
    stats,
    recentActivity,
    canViewAudit,
}: {
    stats: Stat[];
    recentActivity: Pick<
        AuditEntry,
        | 'id'
        | 'user_name'
        | 'action'
        | 'auditable_label'
        | 'auditable_type'
        | 'created_at'
    >[];
    canViewAudit: boolean;
}) {
    const { roles } = usePermissions();

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Dashboard"
                    description={
                        roles.length > 0
                            ? `Signed in as ${roles.join(', ')}.`
                            : undefined
                    }
                />

                {stats.length > 0 ? (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                        {stats.map((stat) => (
                            <StatCard key={stat.label} stat={stat} />
                        ))}
                    </div>
                ) : (
                    <div className="bg-card rounded-xl border p-10 text-center">
                        <p className="font-medium">Nothing assigned yet</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Your account does not yet have access to any ERP
                            module. Ask an administrator to assign you a role.
                        </p>
                    </div>
                )}

                {canViewAudit && (
                    <section className="bg-card rounded-xl border">
                        <div className="flex items-center justify-between border-b px-5 py-4">
                            <div className="flex items-center gap-2">
                                <ScrollText className="text-muted-foreground size-4" />
                                <h2 className="font-semibold">
                                    Recent activity
                                </h2>
                            </div>
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={auditIndex()}>View all</Link>
                            </Button>
                        </div>

                        {recentActivity.length === 0 ? (
                            <p className="text-muted-foreground p-5 text-sm">
                                Nothing has been recorded yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {recentActivity.map((entry) => (
                                    <li
                                        key={entry.id}
                                        className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {entry.user_name ?? 'System'}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                {entry.action.replace(
                                                    /[._]/g,
                                                    ' ',
                                                )}
                                            </span>{' '}
                                            {entry.auditable_label && (
                                                <span className="font-medium">
                                                    {entry.auditable_label}
                                                </span>
                                            )}
                                        </span>
                                        <time
                                            className="text-muted-foreground text-xs"
                                            dateTime={entry.created_at}
                                        >
                                            {new Date(
                                                entry.created_at,
                                            ).toLocaleString()}
                                        </time>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                )}
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
