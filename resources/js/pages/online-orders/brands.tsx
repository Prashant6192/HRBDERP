import { Head, useForm } from '@inertiajs/react';
import { Store, UserRound } from 'lucide-react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    SearchableSelect,
    type SearchableOption,
} from '@/components/ui/searchable-select';
import { update } from '@/routes/brands';

type BrandRow = {
    id: number;
    code: string;
    name: string;
    legal_name: string | null;
    gstin: string | null;
    client_id: number | null;
    client: string | null;
    default_warehouse_id: number | null;
    store: string | null;
    is_active: boolean;
    users: { id: number; name: string; email: string }[];
};

function BrandCard({
    brand,
    stores,
    clients,
    agencies,
    ownBrand,
}: {
    brand: BrandRow;
    stores: SearchableOption[];
    clients: SearchableOption[];
    agencies: SearchableOption[];
    ownBrand: string;
}) {
    const form = useForm({
        name: brand.name,
        legal_name: brand.legal_name ?? '',
        gstin: brand.gstin ?? '',
        client_id: brand.client_id ? String(brand.client_id) : '',
        default_warehouse_id: brand.default_warehouse_id
            ? String(brand.default_warehouse_id)
            : '',
        is_active: brand.is_active,
        user_ids: brand.users.map((u) => u.id),
    });

    const id = (k: string) => `brand-${brand.id}-${k}`;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({
                    ...d,
                    client_id: d.client_id === '' ? null : Number(d.client_id),
                    default_warehouse_id:
                        d.default_warehouse_id === ''
                            ? null
                            : Number(d.default_warehouse_id),
                    legal_name: d.legal_name || null,
                    gstin: d.gstin || null,
                }));
                form.patch(update(brand.id).url, { preserveScroll: true });
            }}
            className="bg-card space-y-5 rounded-xl border p-5"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-lg font-semibold">{brand.name}</h2>
                    <p className="text-muted-foreground text-xs">
                        {brand.store ? (
                            <span className="inline-flex items-center gap-1">
                                <Store className="size-3" /> Ships from{' '}
                                {brand.store}
                            </span>
                        ) : (
                            <span className="text-red-700 dark:text-red-300">
                                No store set: its labels cannot be uploaded yet.
                            </span>
                        )}
                    </p>
                </div>
                <StatusBadge variant={brand.is_active ? 'success' : 'muted'}>
                    {brand.is_active ? 'Active' : 'Inactive'}
                </StatusBadge>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1">
                    <Label htmlFor={id('name')}>Name</Label>
                    <Input
                        id={id('name')}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                    />
                    <InputError message={form.errors.name} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('store')}>
                        Ships from (finished goods store)
                    </Label>
                    <SearchableSelect
                        id={id('store')}
                        value={form.data.default_warehouse_id}
                        onValueChange={(v) =>
                            form.setData('default_warehouse_id', v)
                        }
                        options={stores}
                        placeholder="Choose the store"
                        clearable
                    />
                    <InputError message={form.errors.default_warehouse_id} />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('client')}>Whose stock it sells</Label>
                    <SearchableSelect
                        id={id('client')}
                        value={form.data.client_id}
                        onValueChange={(v) => form.setData('client_id', v)}
                        options={[
                            {
                                value: '',
                                label: `${ownBrand} (the company's own)`,
                            },
                            ...clients,
                        ]}
                    />
                    <p className="text-muted-foreground text-xs">
                        A contract client&rsquo;s brand takes only that
                        client&rsquo;s batches.
                    </p>
                </div>
                <div className="space-y-1">
                    <Label htmlFor={id('gstin')}>
                        Selling GSTIN (optional)
                    </Label>
                    <Input
                        id={id('gstin')}
                        value={form.data.gstin}
                        onChange={(e) =>
                            form.setData('gstin', e.target.value.toUpperCase())
                        }
                        className="font-mono"
                    />
                    <InputError message={form.errors.gstin} />
                </div>
                <div className="space-y-1 sm:col-span-2">
                    <Label htmlFor={id('legal')}>Legal name (optional)</Label>
                    <Input
                        id={id('legal')}
                        value={form.data.legal_name}
                        onChange={(e) =>
                            form.setData('legal_name', e.target.value)
                        }
                    />
                </div>
            </div>

            <div className="space-y-2">
                <Label className="flex items-center gap-2">
                    <UserRound className="size-4" /> Agency accounts that upload
                    for {brand.name}
                </Label>
                {agencies.length === 0 ? (
                    <p className="text-muted-foreground text-sm">
                        No account has the E-commerce Agency role yet. Create
                        one under Employees and give it that role.
                    </p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {agencies.map((a) => {
                            const on = form.data.user_ids.includes(
                                Number(a.value),
                            );

                            return (
                                <label
                                    key={a.value}
                                    className="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        checked={on}
                                        onChange={() =>
                                            form.setData(
                                                'user_ids',
                                                on
                                                    ? form.data.user_ids.filter(
                                                          (x) =>
                                                              x !==
                                                              Number(a.value),
                                                      )
                                                    : [
                                                          ...form.data.user_ids,
                                                          Number(a.value),
                                                      ],
                                            )
                                        }
                                    />
                                    <span>
                                        {a.label}
                                        {a.hint && (
                                            <span className="text-muted-foreground block text-xs">
                                                {a.hint}
                                            </span>
                                        )}
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                )}
            </div>

            <div className="flex items-center justify-between gap-3">
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) =>
                            form.setData('is_active', e.target.checked)
                        }
                    />
                    Active
                </label>
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                >
                    Save {brand.name}
                </Button>
            </div>
        </form>
    );
}

export default function Brands({
    brands,
    stores,
    clients,
    agencies,
    own_brand,
}: {
    brands: BrandRow[];
    stores: SearchableOption[];
    clients: SearchableOption[];
    agencies: SearchableOption[];
    own_brand: string;
}) {
    return (
        <>
            <Head title="Brands" />
            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Brands"
                    description="The brands sold online: the store their parcels leave from, whose stock they take, and the agency accounts that upload their labels."
                />
                <div className="grid gap-6 xl:grid-cols-2">
                    {brands.map((b) => (
                        <BrandCard
                            key={b.id}
                            brand={b}
                            stores={stores}
                            clients={clients}
                            agencies={agencies}
                            ownBrand={own_brand}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}
