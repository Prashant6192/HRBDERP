import { Head, useForm } from '@inertiajs/react';
import { Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { ActiveBadge, StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { dashboard } from '@/routes';
import { index, store as storeRoute, update } from '@/routes/store-categories';

type Category = {
    id: number;
    code: string;
    name: string;
    badge: string;
    kind: string;
    kind_label: string;
    icon: string | null;
    color: string | null;
    description: string | null;
    is_system: boolean;
    is_active: boolean;
    sort_order: number;
    stores_count: number;
};
type Kind = { value: string; label: string; badge: string };
type Draft = {
    code: string;
    name: string;
    badge: string;
    kind: string;
    icon: string;
    color: string;
    description: string;
    is_active: boolean;
    sort_order: string;
};

const EMPTY: Draft = {
    code: '',
    name: '',
    badge: '',
    kind: 'general',
    icon: '',
    color: '',
    description: '',
    is_active: true,
    sort_order: '100',
};

function CategoryForm({
    initial,
    kinds,
    action,
    method,
    locked,
    onDone,
}: {
    initial: Draft;
    kinds: Kind[];
    action: string;
    method: 'post' | 'put';
    locked: boolean;
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
            className="grid gap-3 border-b p-5 sm:grid-cols-2 lg:grid-cols-6"
        >
            <Field
                label="Code"
                htmlFor="cat-code"
                required
                error={form.errors.code}
            >
                <Input
                    id="cat-code"
                    value={form.data.code}
                    onChange={(e) =>
                        form.setData('code', e.target.value.toUpperCase())
                    }
                />
            </Field>
            <Field
                label="Name"
                htmlFor="cat-name"
                required
                error={form.errors.name}
                className="lg:col-span-2"
            >
                <Input
                    id="cat-name"
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                />
            </Field>
            <Field
                label="Badge"
                htmlFor="cat-badge"
                required
                error={form.errors.badge}
                hint="Shown on lists, e.g. RM"
            >
                <Input
                    id="cat-badge"
                    maxLength={12}
                    value={form.data.badge}
                    onChange={(e) =>
                        form.setData('badge', e.target.value.toUpperCase())
                    }
                />
            </Field>
            <Field
                label="Holds"
                htmlFor="cat-kind"
                required
                error={form.errors.kind}
                hint={
                    locked
                        ? 'Fixed while stores use it'
                        : 'The behaviour the workflows rely on'
                }
            >
                <Select
                    value={form.data.kind}
                    onValueChange={(v) => form.setData('kind', v)}
                    disabled={locked}
                >
                    <SelectTrigger id="cat-kind" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {kinds.map((k) => (
                            <SelectItem key={k.value} value={k.value}>
                                {k.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field
                label="Order"
                htmlFor="cat-order"
                error={form.errors.sort_order}
            >
                <Input
                    id="cat-order"
                    type="number"
                    value={form.data.sort_order}
                    onChange={(e) => form.setData('sort_order', e.target.value)}
                />
            </Field>
            <Field
                label="Icon"
                htmlFor="cat-icon"
                error={form.errors.icon}
                hint="A lucide icon name"
            >
                <Input
                    id="cat-icon"
                    value={form.data.icon}
                    onChange={(e) => form.setData('icon', e.target.value)}
                />
            </Field>
            <Field label="Colour" htmlFor="cat-color" error={form.errors.color}>
                <Input
                    id="cat-color"
                    value={form.data.color}
                    onChange={(e) => form.setData('color', e.target.value)}
                />
            </Field>
            <Field
                label="Description"
                htmlFor="cat-desc"
                error={form.errors.description}
                className="lg:col-span-3"
            >
                <Input
                    id="cat-desc"
                    value={form.data.description}
                    onChange={(e) =>
                        form.setData('description', e.target.value)
                    }
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
            {form.errors.is_active && (
                <p className="text-destructive text-sm lg:col-span-6">
                    {form.errors.is_active}
                </p>
            )}
        </form>
    );
}

export default function StoreCategories({
    categories,
    kinds,
    can,
}: {
    categories: Category[];
    kinds: Kind[];
    can: { edit: boolean };
}) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title="Store categories" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Store categories"
                    description="The kinds of store a facility can have. Rename, re-badge, add or deactivate; a category in use is never deleted."
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
                                {adding ? 'Cancel' : 'Add category'}
                            </Button>
                        )
                    }
                />
                <section className="bg-card rounded-xl border">
                    {adding && (
                        <CategoryForm
                            initial={EMPTY}
                            kinds={kinds}
                            action={storeRoute().url}
                            method="post"
                            locked={false}
                            onDone={() => setAdding(false)}
                        />
                    )}
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Badge</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Holds</TableHead>
                                <TableHead className="text-right">
                                    Stores
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="w-24"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {categories.map((c) => (
                                <>
                                    <TableRow key={c.id}>
                                        <TableCell>
                                            <span className="bg-primary/10 text-primary rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold">
                                                {c.badge}
                                            </span>
                                            <span className="text-muted-foreground ml-2 font-mono text-xs">
                                                {c.code}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <div className="font-medium">
                                                {c.name}
                                            </div>
                                            {c.description && (
                                                <div className="text-muted-foreground text-xs">
                                                    {c.description}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {c.kind_label}
                                            {c.is_system && (
                                                <StatusBadge
                                                    variant="muted"
                                                    className="ml-2"
                                                >
                                                    System
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {c.stores_count}
                                        </TableCell>
                                        <TableCell>
                                            <ActiveBadge active={c.is_active} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {can.edit && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        setEditing(
                                                            editing === c.id
                                                                ? null
                                                                : c.id,
                                                        );
                                                        setAdding(false);
                                                    }}
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                    {editing === c.id && (
                                        <TableRow key={`${c.id}-edit`}>
                                            <TableCell
                                                colSpan={6}
                                                className="p-0"
                                            >
                                                <CategoryForm
                                                    initial={{
                                                        code: c.code,
                                                        name: c.name,
                                                        badge: c.badge,
                                                        kind: c.kind,
                                                        icon: c.icon ?? '',
                                                        color: c.color ?? '',
                                                        description:
                                                            c.description ?? '',
                                                        is_active: c.is_active,
                                                        sort_order: String(
                                                            c.sort_order,
                                                        ),
                                                    }}
                                                    kinds={kinds}
                                                    action={update(c.id).url}
                                                    method="put"
                                                    locked={c.stores_count > 0}
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

StoreCategories.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Store categories', href: index() },
    ],
};
