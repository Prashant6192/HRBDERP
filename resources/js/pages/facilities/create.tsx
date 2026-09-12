import { Head, useForm } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Field, FormSection } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index, store as storeRoute } from '@/routes/facilities';
import type {
    CapabilityMeta,
    FacilityTypeOption,
    SelectOption,
    StoreCategoryOption,
} from '@/types';
import {
    CAPABILITY_KEYS,
    CapabilitySwitches,
    EMPTY_DETAILS,
    FacilityDetailsFields,
    capabilitiesFrom,
    type Capabilities,
    type FacilityDetails,
} from './facility-form';

type StoreDraft = {
    store_category_id: number;
    name: string;
    code: string;
    default_location: string;
    manager_id: string;
    is_active: boolean;
};

type EmployeeDraft = {
    user_id: string;
    is_primary: boolean;
    designation: string;
};

type WizardForm = FacilityDetails &
    Capabilities & {
        stores: StoreDraft[];
        employees: EmployeeDraft[];
        opening_stock: 'now' | 'later';
        opening_stock_enabled: boolean;
        is_active: boolean;
    };

const STEPS = [
    { key: 'details', title: 'Facility details' },
    { key: 'capabilities', title: 'Capabilities' },
    { key: 'stores', title: 'Stores' },
    { key: 'employees', title: 'Employees' },
    { key: 'inventory', title: 'Inventory setup' },
];

/** Which stores a facility of this kind usually starts with. */
function suggestedCategories(
    type: FacilityTypeOption | undefined,
    categories: StoreCategoryOption[],
): number[] {
    const kinds = type?.defaults.can_manufacture
        ? [
              'raw_material',
              'packaging',
              'finished_goods',
              'quarantine',
              'rejected',
              'production_staging',
          ]
        : ['finished_goods'];

    return categories.filter((c) => kinds.includes(c.kind)).map((c) => c.id);
}

export default function CreateFacility({
    types,
    categories,
    capabilities,
    managers,
    employees,
}: {
    types: FacilityTypeOption[];
    categories: StoreCategoryOption[];
    capabilities: CapabilityMeta[];
    managers: SelectOption[];
    employees: SelectOption[];
}) {
    const [step, setStep] = useState(0);

    const form = useForm<WizardForm>({
        ...EMPTY_DETAILS,
        ...capabilitiesFrom({
            can_store: true,
            can_receive: true,
            can_dispatch: true,
        }),
        stores: [],
        employees: [],
        opening_stock: 'later',
        opening_stock_enabled: true,
        is_active: true,
    });

    const type = useMemo(
        () => types.find((t) => String(t.value) === form.data.facility_type_id),
        [types, form.data.facility_type_id],
    );

    const caps = capabilitiesFrom(form.data);
    const stem = (form.data.code || form.data.city || form.data.name)
        .replace(/^FAC-/i, '')
        .replace(/[^A-Za-z]/g, '')
        .slice(0, 3)
        .toUpperCase();

    const applyType = (chosen: FacilityTypeOption | undefined) => {
        if (!chosen) return;
        const next = capabilitiesFrom(chosen.defaults);
        const suggested = suggestedCategories(chosen, categories);
        form.setData({
            ...form.data,
            facility_type_id: String(chosen.value),
            ...next,
            stores: suggested.map((id) => draftFor(id)),
        });
    };

    const draftFor = (categoryId: number): StoreDraft => {
        const category = categories.find((c) => c.id === categoryId);
        return {
            store_category_id: categoryId,
            name: category ? `${category.name} Store` : '',
            code: '',
            default_location: '',
            manager_id: '',
            is_active: true,
        };
    };

    const toggleStore = (categoryId: number, on: boolean) => {
        const others = form.data.stores.filter(
            (s) => s.store_category_id !== categoryId,
        );
        form.setData('stores', on ? [...others, draftFor(categoryId)] : others);
    };

    const updateStore = (categoryId: number, patch: Partial<StoreDraft>) =>
        form.setData(
            'stores',
            form.data.stores.map((s) =>
                s.store_category_id === categoryId ? { ...s, ...patch } : s,
            ),
        );

    const stepErrors = (keys: string[]) =>
        keys.some((k) =>
            Object.keys(form.errors).some(
                (e) => e === k || e.startsWith(`${k}.`),
            ),
        );

    const detailKeys = Object.keys(EMPTY_DETAILS);
    const errorSteps = [
        stepErrors(detailKeys),
        stepErrors(CAPABILITY_KEYS),
        stepErrors(['stores']),
        stepErrors(['employees']),
        stepErrors(['opening_stock', 'opening_stock_enabled']),
    ];

    const canAdvance = () => {
        if (step === 0) {
            return (
                form.data.name.trim() !== '' &&
                form.data.facility_type_id !== ''
            );
        }
        return true;
    };

    const submit = () => form.post(storeRoute().url, { preserveScroll: true });

    return (
        <>
            <Head title="New facility" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Set up a facility"
                    description="Five short steps: what it is, what it may do, its stores, who works there, and how its stock starts."
                />

                <ol className="grid gap-2 sm:grid-cols-5">
                    {STEPS.map((s, i) => (
                        <li key={s.key}>
                            <button
                                type="button"
                                onClick={() => i < step && setStep(i)}
                                className={cn(
                                    'flex w-full items-center gap-3 rounded-xl border px-3 py-2 text-left text-sm',
                                    i === step
                                        ? 'border-primary bg-primary/5'
                                        : 'bg-card',
                                    errorSteps[i] && 'border-destructive',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                        i < step
                                            ? 'bg-primary text-primary-foreground'
                                            : i === step
                                              ? 'border-primary text-primary border'
                                              : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {i < step ? (
                                        <Check className="size-3" />
                                    ) : (
                                        i + 1
                                    )}
                                </span>
                                <span className="truncate">{s.title}</span>
                            </button>
                        </li>
                    ))}
                </ol>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (step < STEPS.length - 1) {
                            setStep(step + 1);
                        } else {
                            submit();
                        }
                    }}
                    className="space-y-6"
                >
                    {step === 0 && (
                        <FacilityDetailsFields
                            data={form.data}
                            setField={(k, v) =>
                                form.setData({ ...form.data, [k]: v })
                            }
                            errors={form.errors}
                            types={types}
                            managers={managers}
                            onTypeChange={applyType}
                        />
                    )}

                    {step === 1 && (
                        <CapabilitySwitches
                            value={caps}
                            onChange={(next) =>
                                form.setData({ ...form.data, ...next })
                            }
                            capabilities={capabilities}
                            error={form.errors.can_manufacture}
                        />
                    )}

                    {step === 2 && (
                        <FormSection
                            title="Stores at this facility"
                            description="Tick the stores the facility has today. More can be added later from the facility page without touching what is already recorded."
                        >
                            <div className="space-y-3 sm:col-span-2">
                                {form.errors.stores && (
                                    <p className="text-destructive text-sm">
                                        {form.errors.stores}
                                    </p>
                                )}
                                {categories.map((category) => {
                                    const draft = form.data.stores.find(
                                        (s) =>
                                            s.store_category_id === category.id,
                                    );
                                    const idx = form.data.stores.findIndex(
                                        (s) =>
                                            s.store_category_id === category.id,
                                    );
                                    const err = (field: string) =>
                                        idx >= 0
                                            ? (
                                                  form.errors as Record<
                                                      string,
                                                      string
                                                  >
                                              )[`stores.${idx}.${field}`]
                                            : undefined;

                                    return (
                                        <div
                                            key={category.id}
                                            className={cn(
                                                'rounded-xl border p-4',
                                                draft &&
                                                    'border-primary/40 bg-primary/5',
                                            )}
                                        >
                                            <label className="flex cursor-pointer items-start gap-3">
                                                <Checkbox
                                                    checked={Boolean(draft)}
                                                    onCheckedChange={(c) =>
                                                        toggleStore(
                                                            category.id,
                                                            c === true,
                                                        )
                                                    }
                                                />
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <span className="bg-primary/10 text-primary rounded-md px-1.5 py-0.5 font-mono text-[11px] font-semibold">
                                                            {category.badge}
                                                        </span>
                                                        <span className="font-medium">
                                                            {category.name}
                                                        </span>
                                                    </div>
                                                    {category.description && (
                                                        <p className="text-muted-foreground text-xs">
                                                            {
                                                                category.description
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                            </label>

                                            {draft && (
                                                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                                    <Field
                                                        label="Store name"
                                                        htmlFor={`store-name-${category.id}`}
                                                        error={err('name')}
                                                    >
                                                        <Input
                                                            id={`store-name-${category.id}`}
                                                            value={draft.name}
                                                            onChange={(e) =>
                                                                updateStore(
                                                                    category.id,
                                                                    {
                                                                        name: e
                                                                            .target
                                                                            .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                    <Field
                                                        label="Store code"
                                                        htmlFor={`store-code-${category.id}`}
                                                        error={err('code')}
                                                        hint={`Blank → ${stem || 'XXX'}-${category.badge}`}
                                                    >
                                                        <Input
                                                            id={`store-code-${category.id}`}
                                                            value={draft.code}
                                                            onChange={(e) =>
                                                                updateStore(
                                                                    category.id,
                                                                    {
                                                                        code: e.target.value.toUpperCase(),
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                    <Field
                                                        label="Default location"
                                                        htmlFor={`store-loc-${category.id}`}
                                                        error={err(
                                                            'default_location',
                                                        )}
                                                        hint="Optional zone or rack"
                                                    >
                                                        <Input
                                                            id={`store-loc-${category.id}`}
                                                            value={
                                                                draft.default_location
                                                            }
                                                            onChange={(e) =>
                                                                updateStore(
                                                                    category.id,
                                                                    {
                                                                        default_location:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </Field>
                                                    <Field
                                                        label="Store manager"
                                                        htmlFor={`store-mgr-${category.id}`}
                                                        error={err(
                                                            'manager_id',
                                                        )}
                                                    >
                                                        <Select
                                                            value={
                                                                draft.manager_id ||
                                                                'none'
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                updateStore(
                                                                    category.id,
                                                                    {
                                                                        manager_id:
                                                                            v ===
                                                                            'none'
                                                                                ? ''
                                                                                : v,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger
                                                                id={`store-mgr-${category.id}`}
                                                                className="w-full"
                                                            >
                                                                <SelectValue placeholder="Unassigned" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="none">
                                                                    Unassigned
                                                                </SelectItem>
                                                                {managers.map(
                                                                    (m) => (
                                                                        <SelectItem
                                                                            key={
                                                                                m.value
                                                                            }
                                                                            value={String(
                                                                                m.value,
                                                                            )}
                                                                        >
                                                                            {
                                                                                m.label
                                                                            }
                                                                        </SelectItem>
                                                                    ),
                                                                )}
                                                            </SelectContent>
                                                        </Select>
                                                    </Field>
                                                </div>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        </FormSection>
                    )}

                    {step === 3 && (
                        <FormSection
                            title="Employees"
                            description="Who works at this facility. Optional now; people can be assigned later from the facility or their employee record."
                        >
                            <div className="space-y-3 sm:col-span-2">
                                {form.data.employees.map((row, i) => (
                                    <div
                                        key={i}
                                        className="grid gap-3 rounded-xl border p-3 sm:grid-cols-[1fr_1fr_auto_auto] sm:items-end"
                                    >
                                        <Field
                                            label="Employee"
                                            htmlFor={`emp-${i}`}
                                            error={
                                                (
                                                    form.errors as Record<
                                                        string,
                                                        string
                                                    >
                                                )[`employees.${i}.user_id`]
                                            }
                                        >
                                            <Select
                                                value={row.user_id}
                                                onValueChange={(v) =>
                                                    form.setData(
                                                        'employees',
                                                        form.data.employees.map(
                                                            (e, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...e,
                                                                          user_id:
                                                                              v,
                                                                      }
                                                                    : e,
                                                        ),
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id={`emp-${i}`}
                                                    className="w-full"
                                                >
                                                    <SelectValue placeholder="Choose a person" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {employees.map((e) => (
                                                        <SelectItem
                                                            key={e.value}
                                                            value={String(
                                                                e.value,
                                                            )}
                                                        >
                                                            {e.label}
                                                            {e.description
                                                                ? ` · ${e.description}`
                                                                : ''}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </Field>
                                        <Field
                                            label="Designation here"
                                            htmlFor={`emp-des-${i}`}
                                        >
                                            <Input
                                                id={`emp-des-${i}`}
                                                value={row.designation}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'employees',
                                                        form.data.employees.map(
                                                            (r, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...r,
                                                                          designation:
                                                                              e
                                                                                  .target
                                                                                  .value,
                                                                      }
                                                                    : r,
                                                        ),
                                                    )
                                                }
                                            />
                                        </Field>
                                        <label className="flex items-center gap-2 pb-2 text-sm">
                                            <Checkbox
                                                checked={row.is_primary}
                                                onCheckedChange={(c) =>
                                                    form.setData(
                                                        'employees',
                                                        form.data.employees.map(
                                                            (r, j) =>
                                                                j === i
                                                                    ? {
                                                                          ...r,
                                                                          is_primary:
                                                                              c ===
                                                                              true,
                                                                      }
                                                                    : r,
                                                        ),
                                                    )
                                                }
                                            />
                                            Primary
                                        </label>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() =>
                                                form.setData(
                                                    'employees',
                                                    form.data.employees.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                ))}
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        form.setData('employees', [
                                            ...form.data.employees,
                                            {
                                                user_id: '',
                                                is_primary: true,
                                                designation: '',
                                            },
                                        ])
                                    }
                                >
                                    <Plus className="size-4" />
                                    Add employee
                                </Button>
                                {form.data.employees.length === 0 && (
                                    <p className="text-muted-foreground text-sm">
                                        Nobody yet — that is fine. Skip this
                                        step and assign people later.
                                    </p>
                                )}
                            </div>
                        </FormSection>
                    )}

                    {step === 4 && (
                        <FormSection
                            title="Inventory setup"
                            description="How the facility's stock starts. Opening stock is booked through the ledger like everything else; there is no editable stock number."
                        >
                            <div className="space-y-3 sm:col-span-2">
                                {(
                                    [
                                        [
                                            'now',
                                            'Book opening stock now',
                                            'Go straight to the opening stock screen after saving.',
                                        ],
                                        [
                                            'later',
                                            'Start empty, book later',
                                            'Stock arrives through receipts and transfers; opening stock stays available until switched off.',
                                        ],
                                    ] as const
                                ).map(([value, title, hint]) => (
                                    <label
                                        key={value}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-xl border p-4',
                                            form.data.opening_stock === value &&
                                                'border-primary bg-primary/5',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="opening_stock"
                                            value={value}
                                            checked={
                                                form.data.opening_stock ===
                                                value
                                            }
                                            onChange={() =>
                                                form.setData(
                                                    'opening_stock',
                                                    value,
                                                )
                                            }
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="font-medium">
                                                {title}
                                            </span>
                                            <p className="text-muted-foreground text-xs">
                                                {hint}
                                            </p>
                                        </span>
                                    </label>
                                ))}

                                <div className="rounded-xl border p-4">
                                    <h3 className="font-medium">Summary</h3>
                                    <dl className="text-muted-foreground mt-2 grid gap-1 text-sm sm:grid-cols-2">
                                        <dt>Facility</dt>
                                        <dd className="text-foreground">
                                            {form.data.name || '—'}{' '}
                                            {form.data.code &&
                                                `(${form.data.code})`}
                                        </dd>
                                        <dt>Type</dt>
                                        <dd className="text-foreground">
                                            {type?.label ?? '—'}
                                        </dd>
                                        <dt>Capabilities</dt>
                                        <dd className="text-foreground">
                                            {capabilities
                                                .filter((c) => caps[c.key])
                                                .map((c) => c.label)
                                                .join(', ') || 'None'}
                                        </dd>
                                        <dt>Stores</dt>
                                        <dd className="text-foreground">
                                            {form.data.stores
                                                .map(
                                                    (s) =>
                                                        categories.find(
                                                            (c) =>
                                                                c.id ===
                                                                s.store_category_id,
                                                        )?.badge,
                                                )
                                                .filter(Boolean)
                                                .join(' · ') || 'None'}
                                        </dd>
                                        <dt>Employees</dt>
                                        <dd className="text-foreground">
                                            {
                                                form.data.employees.filter(
                                                    (e) => e.user_id,
                                                ).length
                                            }
                                        </dd>
                                    </dl>
                                </div>
                                <Label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.is_active}
                                        onCheckedChange={(c) =>
                                            form.setData(
                                                'is_active',
                                                c === true,
                                            )
                                        }
                                    />
                                    Facility is active from today
                                </Label>
                            </div>
                        </FormSection>
                    )}

                    <div className="flex items-center justify-between gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={step === 0}
                            onClick={() => setStep(step - 1)}
                        >
                            <ChevronLeft className="size-4" />
                            Back
                        </Button>
                        <div className="flex items-center gap-2">
                            {step === 3 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => setStep(4)}
                                >
                                    Skip
                                </Button>
                            )}
                            {step < STEPS.length - 1 ? (
                                <Button type="submit" disabled={!canAdvance()}>
                                    Next
                                    <ChevronRight className="size-4" />
                                </Button>
                            ) : (
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    <Check className="size-4" />
                                    Create facility
                                </Button>
                            )}
                        </div>
                    </div>
                </form>
            </div>
        </>
    );
}

CreateFacility.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facilities & Warehouses', href: index() },
        { title: 'New facility', href: '#' },
    ],
};
