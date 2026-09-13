import { Head, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import { Field, FormSection } from '@/components/form-field';
import { StatusBadge } from '@/components/status-badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { dashboard } from '@/routes';
import { create, index, store } from '@/routes/plans';
import type { FormulaOwnership, SelectOption } from '@/types';

type FormulaOption = SelectOption & {
    product: string | null;
    product_client_id: number | null;
    ownership: FormulaOwnership;
    ownership_label: string;
    client_id: number | null;
    client: string | null;
    materials: { value: number; label: string; kind: string }[];
    net_content: string | null;
    net_content_uom: string | null;
    version: number | null;
    batch_uom_id: number | null;
    batch_uom: string | null;
};

type UomOption = SelectOption & { dimension: string };

export default function CreatePlan({
    formulas,
    uoms,
    facilities,
    today,
    clients,
    manufacturingTypes,
    materialSources,
}: {
    formulas: FormulaOption[];
    uoms: UomOption[];
    facilities: SelectOption[];
    today: string;
    clients: SelectOption[];
    manufacturingTypes: SelectOption[];
    materialSources: SelectOption[];
}) {
    const params =
        typeof window !== 'undefined'
            ? new URLSearchParams(window.location.search)
            : new URLSearchParams();
    const presetFacility = params.get('facility');
    const presetClient = params.get('client');
    const presetType =
        params.get('type') === 'third_party' || presetClient
            ? 'third_party'
            : 'own';

    const form = useForm({
        manufacturing_type: presetType,
        client_id:
            presetClient &&
            clients.some((c) => String(c.value) === presetClient)
                ? presetClient
                : '',
        client_po_ref: '',
        client_product_name: '',
        required_delivery_at: '',
        material_source: 'company',
        client_supplied_item_ids: [] as number[],
        formula_id: '',
        facility_id:
            presetFacility &&
            facilities.some((f) => String(f.value) === presetFacility)
                ? presetFacility
                : facilities.length === 1
                  ? String(facilities[0].value)
                  : '',
        quantity: '',
        uom_id: '',
        planned_start_date: '',
        notes: '',
    });

    const formula = useMemo(
        () => formulas.find((f) => String(f.value) === form.data.formula_id),
        [formulas, form.data.formula_id],
    );

    const thirdParty = form.data.manufacturing_type === 'third_party';
    const clientId = thirdParty ? Number(form.data.client_id) || null : null;

    // A client's formula is offered for that client alone; a company formula
    // for anyone.
    const offeredFormulas = formulas.filter(
        (f) => f.ownership === 'company' || f.client_id === clientId,
    );

    const toggleSupplied = (id: number, on: boolean) =>
        form.setData(
            'client_supplied_item_ids',
            on
                ? [...form.data.client_supplied_item_ids, id]
                : form.data.client_supplied_item_ids.filter((v) => v !== id),
        );

    const chooseFormula = (id: string) => {
        const chosen = formulas.find((f) => String(f.value) === id);
        form.setData({
            ...form.data,
            formula_id: id,
            uom_id: chosen?.batch_uom_id
                ? String(chosen.batch_uom_id)
                : form.data.uom_id,
        });
    };

    const estimatedUnits = (() => {
        if (!formula?.net_content || !form.data.quantity) {
            return null;
        }

        const uom = uoms.find((u) => String(u.value) === form.data.uom_id);
        const q = Number(form.data.quantity);

        if (!uom || !Number.isFinite(q)) {
            return null;
        }

        // A rough figure for the screen only (1 g = 1 ml; kg/L = ×1000).
        // The server works it out properly with the product's density.
        const code = String(uom.label).split(' ')[0];
        const base = code === 'KG' || code === 'L' ? q * 1000 : q;
        const perUnit = Number(formula.net_content);

        return perUnit > 0 ? Math.floor(base / perUnit) : null;
    })();

    return (
        <>
            <Head title="Plan a batch" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Plan a batch"
                    description="Pick the formula and how much to make. The stores are checked the moment you save."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(store().url);
                    }}
                    className="space-y-6"
                >
                    <FormSection
                        title="Whose batch"
                        description="The factory works the same way for both. A third-party batch names the client, their PO, and whose material goes in."
                    >
                        <Field
                            label="Manufacturing type"
                            htmlFor="manufacturing_type"
                            required
                            error={form.errors.manufacturing_type}
                        >
                            <Select
                                value={form.data.manufacturing_type}
                                onValueChange={(v) =>
                                    form.setData({
                                        ...form.data,
                                        manufacturing_type: v,
                                        client_id:
                                            v === 'own'
                                                ? ''
                                                : form.data.client_id,
                                        formula_id: '',
                                        material_source: 'company',
                                        client_supplied_item_ids: [],
                                    })
                                }
                            >
                                <SelectTrigger
                                    id="manufacturing_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {manufacturingTypes.map((t) => (
                                        <SelectItem
                                            key={t.value}
                                            value={String(t.value)}
                                        >
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        {thirdParty && (
                            <>
                                <Field
                                    label="Client / party"
                                    htmlFor="client_id"
                                    required
                                    error={form.errors.client_id}
                                >
                                    <Select
                                        value={form.data.client_id}
                                        onValueChange={(v) =>
                                            form.setData({
                                                ...form.data,
                                                client_id: v,
                                                formula_id: '',
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="client_id"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Choose the client" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {clients.map((c) => (
                                                <SelectItem
                                                    key={c.value}
                                                    value={String(c.value)}
                                                >
                                                    {c.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field
                                    label="Client PO / work order"
                                    htmlFor="client_po_ref"
                                    error={form.errors.client_po_ref}
                                >
                                    <Input
                                        id="client_po_ref"
                                        value={form.data.client_po_ref}
                                        onChange={(e) =>
                                            form.setData(
                                                'client_po_ref',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="ABC/PO/2026/145"
                                    />
                                </Field>
                                <Field
                                    label="Client's product name"
                                    htmlFor="client_product_name"
                                    error={form.errors.client_product_name}
                                    hint="As the client calls it, if different from ours."
                                >
                                    <Input
                                        id="client_product_name"
                                        value={form.data.client_product_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'client_product_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Required delivery date"
                                    htmlFor="required_delivery_at"
                                    error={form.errors.required_delivery_at}
                                >
                                    <Input
                                        id="required_delivery_at"
                                        type="date"
                                        min={today}
                                        value={form.data.required_delivery_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'required_delivery_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Material source"
                                    htmlFor="material_source"
                                    required
                                    error={form.errors.material_source}
                                    hint="Client-supplied material counts only the client's own stock; ours only ours."
                                >
                                    <Select
                                        value={form.data.material_source}
                                        onValueChange={(v) =>
                                            form.setData({
                                                ...form.data,
                                                material_source: v,
                                                client_supplied_item_ids: [],
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            id="material_source"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {materialSources.map((s) => (
                                                <SelectItem
                                                    key={s.value}
                                                    value={String(s.value)}
                                                >
                                                    {s.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </Field>
                                {form.data.material_source === 'mixed' && (
                                    <div className="space-y-2 sm:col-span-2">
                                        <p className="text-sm font-medium">
                                            Materials the client supplies
                                        </p>
                                        {!formula ? (
                                            <p className="text-muted-foreground text-sm">
                                                Choose the formula below first;
                                                its materials are listed here.
                                            </p>
                                        ) : formula.materials.length === 0 ? (
                                            <p className="text-muted-foreground text-sm">
                                                The formula has no materials on
                                                record.
                                            </p>
                                        ) : (
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                {formula.materials.map((m) => (
                                                    <div
                                                        key={`${m.kind}-${m.value}`}
                                                        className="flex items-center gap-2"
                                                    >
                                                        <Checkbox
                                                            id={`supplied-${m.value}`}
                                                            checked={form.data.client_supplied_item_ids.includes(
                                                                m.value,
                                                            )}
                                                            onCheckedChange={(
                                                                v,
                                                            ) =>
                                                                toggleSupplied(
                                                                    m.value,
                                                                    v === true,
                                                                )
                                                            }
                                                        />
                                                        <Label
                                                            htmlFor={`supplied-${m.value}`}
                                                            className="font-normal"
                                                        >
                                                            {m.label}
                                                            <span className="text-muted-foreground ml-1 text-xs">
                                                                {m.kind ===
                                                                'packaging'
                                                                    ? 'packaging'
                                                                    : 'raw material'}
                                                            </span>
                                                        </Label>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        {form.errors
                                            .client_supplied_item_ids && (
                                            <p className="text-destructive text-sm">
                                                {
                                                    form.errors
                                                        .client_supplied_item_ids
                                                }
                                            </p>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </FormSection>

                    <FormSection title="What to make">
                        <Field
                            label="Manufacturing facility"
                            htmlFor="facility_id"
                            required
                            error={form.errors.facility_id}
                            hint="Availability is checked against this facility's stores only."
                        >
                            <Select
                                value={form.data.facility_id}
                                onValueChange={(v) =>
                                    form.setData('facility_id', v)
                                }
                            >
                                <SelectTrigger
                                    id="facility_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose the facility" />
                                </SelectTrigger>
                                <SelectContent>
                                    {facilities.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={String(f.value)}
                                        >
                                            {f.label}
                                            {f.description
                                                ? ` · ${f.description}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Formula"
                            htmlFor="formula_id"
                            required
                            error={form.errors.formula_id}
                            hint="Only formulas with an active recipe are offered."
                        >
                            <Select
                                value={form.data.formula_id}
                                onValueChange={chooseFormula}
                            >
                                <SelectTrigger
                                    id="formula_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a formula" />
                                </SelectTrigger>
                                <SelectContent>
                                    {offeredFormulas.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={String(f.value)}
                                        >
                                            {f.label}
                                            {f.version
                                                ? ` · v${f.version}`
                                                : ''}
                                            {f.ownership !== 'company'
                                                ? ` · ${f.ownership_label}${f.client ? ` — ${f.client}` : ''}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {formula && formula.ownership !== 'company' && (
                                <StatusBadge variant="info" className="mt-2">
                                    {formula.ownership_label}
                                    {formula.client
                                        ? ` — ${formula.client}`
                                        : ''}
                                </StatusBadge>
                            )}
                        </Field>

                        <Field
                            label="Product"
                            htmlFor="product"
                            hint="Comes from the formula; packaging is planned from its pack list."
                        >
                            <Input
                                id="product"
                                value={
                                    formula?.product ??
                                    (formula ? 'No product linked' : '')
                                }
                                readOnly
                                className="bg-muted/40"
                            />
                        </Field>

                        <Field
                            label="Batch quantity"
                            htmlFor="quantity"
                            required
                            error={form.errors.quantity ?? form.errors.uom_id}
                        >
                            <div className="flex gap-2">
                                <Input
                                    id="quantity"
                                    inputMode="decimal"
                                    value={form.data.quantity}
                                    onChange={(e) =>
                                        form.setData('quantity', e.target.value)
                                    }
                                    placeholder="e.g. 100"
                                    required
                                />
                                <Select
                                    value={form.data.uom_id}
                                    onValueChange={(v) =>
                                        form.setData('uom_id', v)
                                    }
                                >
                                    <SelectTrigger className="w-44">
                                        <SelectValue placeholder="Unit" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {uoms.map((u) => (
                                            <SelectItem
                                                key={u.value}
                                                value={String(u.value)}
                                            >
                                                {u.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            {estimatedUnits !== null && formula && (
                                <p className="text-muted-foreground mt-1 text-xs">
                                    About {estimatedUnits.toLocaleString()} ×{' '}
                                    {Number(formula.net_content)}{' '}
                                    {formula.net_content_uom} units.
                                </p>
                            )}
                        </Field>

                        <Field
                            label="Planned start"
                            htmlFor="planned_start_date"
                            error={form.errors.planned_start_date}
                            hint="Becomes the needed-by date on the material requests."
                        >
                            <Input
                                id="planned_start_date"
                                type="date"
                                min={today}
                                value={form.data.planned_start_date}
                                onChange={(e) =>
                                    form.setData(
                                        'planned_start_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>

                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </FormSection>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing
                                ? 'Checking stores…'
                                : 'Save and check stores'}
                        </Button>
                        <p className="text-muted-foreground text-sm">
                            You will see every material with what the store
                            holds, what is short, and what to order.
                        </p>
                    </div>
                </form>
            </div>
        </>
    );
}

CreatePlan.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Production plans', href: index() },
        { title: 'Plan a batch', href: create() },
    ],
};
