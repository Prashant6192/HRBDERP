import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { CapabilityBadges } from '@/components/facilities/badges';
import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { index, store as storeRoute, update } from '@/routes/facility-types';
import type { CapabilityMeta } from '@/types';

type FacilityType = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    default_capabilities: Record<string, boolean>;
    is_system: boolean;
    is_active: boolean;
    sort_order: number;
    facilities_count: number;
};
type Draft = {
    code: string;
    name: string;
    description: string;
    is_active: boolean;
    sort_order: string;
    default_capabilities: Record<string, boolean>;
};

function TypeForm({
    initial,
    capabilities,
    action,
    method,
    onDone,
}: {
    initial: Draft;
    capabilities: CapabilityMeta[];
    action: string;
    method: 'post' | 'put';
    onDone: () => void;
}) {
    const form = useForm<Draft>(initial);
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                (method === 'post' ? form.post : form.put)(action, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
            className="grid gap-3 border-b p-5 sm:grid-cols-2 lg:grid-cols-5"
        >
            <Field
                label="Code"
                htmlFor="ft-code"
                required
                error={form.errors.code}
            >
                <Input
                    id="ft-code"
                    value={form.data.code}
                    onChange={(e) =>
                        form.setData('code', e.target.value.toUpperCase())
                    }
                />
            </Field>
            <Field
                label="Name"
                htmlFor="ft-name"
                required
                error={form.errors.name}
                className="lg:col-span-2"
            >
                <Input
                    id="ft-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </Field>
            <Field
                label="Order"
                htmlFor="ft-order"
                error={form.errors.sort_order}
            >
                <Input
                    id="ft-order"
                    type="number"
                    value={form.data.sort_order}
                    onChange={(e) => form.setData('sort_order', e.target.value)}
                />
            </Field>
            <div className="flex items-end gap-3">
                <label className="flex items-center gap-2 pb-2 text-sm">
                    <Checkbox
                        checked={form.data.is_active}
                        onCheckedChange={(c) =>
                            form.setData('is_active', c === true)
                        }
                    />
                    Active
                </label>
                <Button type="submit" disabled={form.processing}>
                    Save
                </Button>
            </div>
            <Field
                label="Description"
                htmlFor="ft-desc"
                error={form.errors.description}
                className="sm:col-span-2 lg:col-span-5"
            >
                <Input
                    id="ft-desc"
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData('description', e.target.value)
                    }
                />
            </Field>
            <div className="sm:col-span-2 lg:col-span-5">
                <p className="text-muted-foreground mb-2 text-xs tracking-wide uppercase">
                    Default capabilities for new facilities of this type
                </p>
                <div className="flex flex-wrap gap-3">
                    {capabilities.map((c) => (
                        <label
                            key={c.key}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={Boolean(
                                    form.data.default_capabilities[c.key],
                                )}
                                onCheckedChange={(v) =>
                                    form.setData('default_capabilities', {
                                        ...form.data.default_capabilities,
                                        [c.key]: v === true,
                                    })
                                }
                            />
                            {c.label}
                        </label>
                    ))}
                </div>
            </div>
            {form.errors.is_active && (
                <p className="text-destructive text-sm lg:col-span-5">
                    {form.errors.is_active}
                </p>
            )}
        </form>
    );
}

export default function FacilityTypes({
    types,
    capabilities,
    can,
}: {
    types: FacilityType[];
    capabilities: CapabilityMeta[];
    can: { edit: boolean };
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);
    const empty: Draft = {
        code: '',
        name: '',
        description: '',
        is_active: true,
        sort_order: '100',
        default_capabilities: {
            can_store: true,
            can_receive: true,
            can_dispatch: true,
        },
    };

    return (
        <>
            <Head title="Facility types" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Facility types"
                    description="Manufacturing, warehouse, distribution centre, office… and the capabilities each type starts with. A facility can still switch any capability on or off."
                    actions={
                        can.edit && (
                            <Button
                                variant={adding ? 'ghost' : 'default'}
                                onClick={() => {
                                    setAdding(!adding);
                                    setEditing(null);
                                }}
                            >
                                {adding ? (
                                    <X className="size-4" />
                                ) : (
                                    <Plus className="size-4" />
                                )}
                                {adding ? 'Cancel' : 'Add type'}
                            </Button>
                        )
                    }
                />
                <section className="bg-card rounded-xl border">
                    {adding && (
                        <TypeForm
                            initial={empty}
                            capabilities={capabilities}
                            action={storeRoute().url}
                            method="post"
                            onDone={() => setAdding(false)}
                        />
                    )}
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Default capabilities</TableHead>
                                <TableHead className="text-right">
                                    Facilities
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-24"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.map((t) => (
                                <>
                                    <TableRow key={t.id}>
                                        <TableCell className="font-mono text-xs">
                                            {t.code}
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-medium">
                                                {t.name}
                                            </div>
                                            {t.description && (
                                                <div className="text-muted-foreground text-xs">
                                                    {t.description}
                                                </div>
                                            )}
                                            {t.is_system && (
                                                <StatusBadge
                                                    variant="muted"
                                                    className="mt-1"
                                                >
                                                    System
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <CapabilityBadges
                                                capabilities={capabilities
                                                    .filter(
                                                        (c) =>
                                                            t
                                                                .default_capabilities[
                                                                c.key
                                                            ],
                                                    )
                                                    .map((c) => ({
                                                        key: c.key,
                                                        badge: c.badge,
                                                        label: c.label,
                                                    }))}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {t.facilities_count}
                                        </TableCell>
                                        <TableCell>
                                            <ActiveBadge active={t.is_active} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can.edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setEditing(
                                                            editing === t.id
                                                                ? null
                                                                : t.id,
                                                        );
                                                        setAdding(false);
                                                    }}
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                    {editing === t.id && (
                                        <TableRow key={`${t.id}-edit`}>
                                            <TableCell
                                                colSpan={6}
                                                className="p-0"
                                            >
                                                <TypeForm
                                                    initial={{
                                                        code: t.code,
                                                        name: t.name,
                                                        description:
                                                            t.description ?? '',
                                                        is_active: t.is_active,
                                                        sort_order: String(
                                                            t.sort_order,
                                                        ),
                                                        default_capabilities:
                                                            t.default_capabilities,
                                                    }}
                                                    capabilities={capabilities}
                                                    action={update(t.id).url}
                                                    method="put"
                                                    onDone={() =>
                                                        setEditing(null)
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>
        </>
    );
}

FacilityTypes.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facility types', href: index() },
    ],
};
