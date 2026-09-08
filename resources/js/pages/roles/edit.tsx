import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { dashboard } from '@/routes';
import { edit, index, update } from '@/routes/roles';

type CatalogueModule = {
    key: string;
    label: string;
    permissions: { name: string; ability: string }[];
};

type CatalogueGroup = {
    group: string;
    modules: CatalogueModule[];
};

type Role = {
    id: number;
    name: string;
    description: string | null;
    is_super_admin: boolean;
};

export default function EditRole({
    role,
    assigned,
    catalogue,
}: {
    role: Role;
    assigned: string[];
    catalogue: CatalogueGroup[];
}) {
    const [selected, setSelected] = useState<Set<string>>(
        () => new Set(assigned),
    );

    const toggle = (permission: string) => {
        setSelected((current) => {
            const next = new Set(current);

            if (next.has(permission)) {
                next.delete(permission);
            } else {
                next.add(permission);
            }

            return next;
        });
    };

    const toggleModule = (module: CatalogueModule) => {
        setSelected((current) => {
            const next = new Set(current);
            const all = module.permissions.every((p) => next.has(p.name));

            for (const permission of module.permissions) {
                if (all) {
                    next.delete(permission.name);
                } else {
                    next.add(permission.name);
                }
            }

            return next;
        });
    };

    return (
        <>
            <Head title={`Edit ${role.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={role.name}
                    description={
                        role.description ??
                        'Choose what this role is permitted to do.'
                    }
                />

                {role.is_super_admin && (
                    <div className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-800 dark:text-amber-200">
                        Super Admin passes every permission check through a
                        gate, regardless of what is ticked here. Changing this
                        list will not restrict it — remove the role from an
                        account instead.
                    </div>
                )}

                <Form
                    action={update(role.id).url}
                    method="put"
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing }) => (
                        <>
                            {[...selected].map((permission) => (
                                <input
                                    key={permission}
                                    type="hidden"
                                    name="permissions[]"
                                    value={permission}
                                />
                            ))}

                            {catalogue.map((group) => (
                                <section
                                    key={group.group}
                                    className="bg-card rounded-xl border"
                                >
                                    <h2 className="border-b px-5 py-3 font-semibold">
                                        {group.group}
                                    </h2>

                                    <div className="divide-y">
                                        {group.modules.map((module) => {
                                            const allChecked =
                                                module.permissions.every((p) =>
                                                    selected.has(p.name),
                                                );

                                            return (
                                                <div
                                                    key={module.key}
                                                    className="px-5 py-4"
                                                >
                                                    <div className="mb-3 flex items-center justify-between gap-3">
                                                        <h3 className="text-sm font-medium">
                                                            {module.label}
                                                        </h3>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                toggleModule(
                                                                    module,
                                                                )
                                                            }
                                                        >
                                                            {allChecked
                                                                ? 'Clear all'
                                                                : 'Select all'}
                                                        </Button>
                                                    </div>

                                                    <div className="flex flex-wrap gap-x-6 gap-y-3">
                                                        {module.permissions.map(
                                                            (permission) => (
                                                                <label
                                                                    key={
                                                                        permission.name
                                                                    }
                                                                    className="flex cursor-pointer items-center gap-2 text-sm"
                                                                >
                                                                    <Checkbox
                                                                        checked={selected.has(
                                                                            permission.name,
                                                                        )}
                                                                        onCheckedChange={() =>
                                                                            toggle(
                                                                                permission.name,
                                                                            )
                                                                        }
                                                                    />
                                                                    <span className="capitalize">
                                                                        {permission.ability.replace(
                                                                            /_/g,
                                                                            ' ',
                                                                        )}
                                                                    </span>
                                                                </label>
                                                            ),
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </section>
                            ))}

                            <div className="bg-background/80 sticky bottom-0 flex items-center gap-3 border-t py-4 backdrop-blur">
                                <Button type="submit" disabled={processing}>
                                    Save permissions
                                </Button>
                                <p className="text-muted-foreground text-sm">
                                    {selected.size} selected
                                </p>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

EditRole.layout = ({ role }: { role: Role }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Roles', href: index() },
        { title: role.name, href: edit(role.id) },
    ],
});
