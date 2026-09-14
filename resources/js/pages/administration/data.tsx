import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, Sparkles, Trash2 } from 'lucide-react';
import { useMemo } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { data as dataRoute } from '@/routes/administration';
import {
    clear as clearRoute,
    demo as demoRoute,
} from '@/routes/administration/data';

type Scope = {
    key: string;
    label: string;
    description: string;
    requires: string[];
    danger: boolean;
    tables: number;
};

export default function DataAdministration({
    scopes,
    counts,
    company,
    environment,
}: {
    scopes: Scope[];
    counts: Record<string, number>;
    company: string;
    environment: string;
}) {
    const clear = useForm<{
        scopes: string[];
        restart_numbering: boolean;
        confirmation: string;
    }>({ scopes: [], restart_numbering: false, confirmation: '' });

    const demo = useForm({ confirmation: '' });

    const byKey = useMemo(
        () => Object.fromEntries(scopes.map((s) => [s.key, s])),
        [scopes],
    );

    // Choosing a scope brings in whatever it is built on.
    const resolved = useMemo(() => {
        const out = new Set<string>();
        const add = (key: string) => {
            if (out.has(key)) return;
            byKey[key]?.requires.forEach(add);
            out.add(key);
        };
        clear.data.scopes.forEach(add);
        return out;
    }, [clear.data.scopes, byKey]);

    const pulledIn = scopes.filter(
        (s) => resolved.has(s.key) && !clear.data.scopes.includes(s.key),
    );

    const totalRows = scopes
        .filter((s) => resolved.has(s.key))
        .reduce((n, s) => n + (counts[s.key] ?? 0), 0);

    const toggle = (key: string) =>
        clear.setData(
            'scopes',
            clear.data.scopes.includes(key)
                ? clear.data.scopes.filter((k) => k !== key)
                : [...clear.data.scopes, key],
        );

    const confirmed = clear.data.confirmation.trim() === company;

    return (
        <>
            <Head title="Data" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Data"
                    description="Clear what testing left behind, or fill the system with a worked example. Reserved for the system administrator."
                />

                <section className="bg-card rounded-xl border">
                    <div className="border-b px-6 py-4">
                        <h2 className="flex items-center gap-2 font-semibold">
                            <Sparkles className="text-primary size-4" />
                            Fill in demo data
                        </h2>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Builds a worked example on top of what is already
                            here: a plant with its stores, two suppliers and a
                            contract client, materials and a product, an active
                            recipe, opening stock, a delivery through QC, a plan
                            with its material requests, and a batch made from
                            start to finish. It adds; it never deletes, and it
                            creates no login accounts.
                        </p>
                    </div>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            demo.post(demoRoute().url, {
                                preserveScroll: true,
                            });
                        }}
                        className="flex flex-wrap items-end gap-3 px-6 py-5"
                    >
                        <div className="min-w-64 flex-1">
                            <Label htmlFor="demo-confirmation">
                                Type{' '}
                                <span className="font-semibold">{company}</span>{' '}
                                to confirm
                            </Label>
                            <Input
                                id="demo-confirmation"
                                className="mt-1"
                                value={demo.data.confirmation}
                                onChange={(e) =>
                                    demo.setData('confirmation', e.target.value)
                                }
                                placeholder={company}
                            />
                            <InputError message={demo.errors.confirmation} />
                        </div>
                        <Button
                            type="submit"
                            disabled={
                                demo.processing ||
                                demo.data.confirmation.trim() !== company
                            }
                        >
                            Fill in demo data
                        </Button>
                    </form>
                </section>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        clear.post(clearRoute().url, { preserveScroll: true });
                    }}
                >
                    <section className="rounded-xl border border-red-500/30">
                        <div className="border-b border-red-500/30 bg-red-500/5 px-6 py-4">
                            <h2 className="flex items-center gap-2 font-semibold">
                                <AlertTriangle className="size-4 text-red-600" />
                                Clear data
                            </h2>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Tick only what should go. People, roles and
                                permissions, the units and departments the ERP
                                is built on, and the audit trail are never
                                touched. This cannot be undone, so take a backup
                                first.
                            </p>
                            {environment !== 'production' && (
                                <p className="text-muted-foreground mt-2 text-xs">
                                    Environment: {environment}
                                </p>
                            )}
                        </div>

                        <div className="divide-y">
                            {scopes.map((scope) => {
                                const ticked = clear.data.scopes.includes(
                                    scope.key,
                                );
                                const pulled =
                                    resolved.has(scope.key) && !ticked;

                                return (
                                    <label
                                        key={scope.key}
                                        htmlFor={`scope-${scope.key}`}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 px-6 py-4',
                                            (ticked || pulled) &&
                                                'bg-red-500/5',
                                        )}
                                    >
                                        <Checkbox
                                            id={`scope-${scope.key}`}
                                            className="mt-1"
                                            checked={ticked || pulled}
                                            onCheckedChange={() =>
                                                toggle(scope.key)
                                            }
                                        />
                                        <span className="min-w-0 flex-1">
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium">
                                                    {scope.label}
                                                </span>
                                                {scope.danger && (
                                                    <span className="rounded-full border border-red-500/40 px-2 py-0.5 text-xs text-red-700 dark:text-red-300">
                                                        rarely wanted
                                                    </span>
                                                )}
                                                {pulled && (
                                                    <span className="text-muted-foreground text-xs">
                                                        included because
                                                        something above needs it
                                                    </span>
                                                )}
                                            </span>
                                            <span className="text-muted-foreground mt-0.5 block text-sm">
                                                {scope.description}
                                            </span>
                                        </span>
                                        <span className="text-right">
                                            <span className="block text-lg font-semibold tabular-nums">
                                                {(
                                                    counts[scope.key] ?? 0
                                                ).toLocaleString('en-IN')}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                records
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>

                        <div className="space-y-4 border-t px-6 py-5">
                            <InputError message={clear.errors.scopes} />

                            {pulledIn.length > 0 && (
                                <p className="text-sm text-amber-700 dark:text-amber-300">
                                    Also clearing{' '}
                                    {pulledIn
                                        .map((s) => s.label.toLowerCase())
                                        .join(', ')}
                                    , because what you chose is built on it.
                                </p>
                            )}

                            <label
                                htmlFor="restart-numbering"
                                className="flex cursor-pointer items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    id="restart-numbering"
                                    checked={clear.data.restart_numbering}
                                    onCheckedChange={(v) =>
                                        clear.setData(
                                            'restart_numbering',
                                            v === true,
                                        )
                                    }
                                />
                                Restart document numbering, so the next delivery
                                is GRN-0001 again
                            </label>

                            <div className="max-w-md">
                                <Label htmlFor="clear-confirmation">
                                    Type{' '}
                                    <span className="font-semibold">
                                        {company}
                                    </span>{' '}
                                    to confirm
                                </Label>
                                <Input
                                    id="clear-confirmation"
                                    className="mt-1"
                                    value={clear.data.confirmation}
                                    onChange={(e) =>
                                        clear.setData(
                                            'confirmation',
                                            e.target.value,
                                        )
                                    }
                                    placeholder={company}
                                />
                                <InputError
                                    message={clear.errors.confirmation}
                                />
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={
                                        clear.processing ||
                                        !confirmed ||
                                        clear.data.scopes.length === 0
                                    }
                                >
                                    <Trash2 className="size-4" />
                                    Clear{' '}
                                    {totalRows > 0
                                        ? `${totalRows.toLocaleString('en-IN')} records`
                                        : 'the ticked data'}
                                </Button>
                                <p className="text-muted-foreground text-sm">
                                    Take a database backup before you press
                                    this.
                                </p>
                            </div>
                        </div>
                    </section>
                </form>
            </div>
        </>
    );
}

DataAdministration.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Data', href: dataRoute() },
    ],
};
