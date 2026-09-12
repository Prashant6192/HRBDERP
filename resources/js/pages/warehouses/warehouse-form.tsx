import { Form } from '@inertiajs/react';
import { Field, FormSection } from '@/components/form-field';
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
import type { SelectOption, Warehouse } from '@/types';

type WarehouseFormProps = {
    /** Omitted when creating. */
    warehouse?: Warehouse;
    types: SelectOption[];
    managers: SelectOption[];
    facilities?: SelectOption[];
    categories?: (SelectOption & { kind: string })[];
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
};

/**
 * One form for creating and editing, so the two screens cannot drift apart.
 */
export function WarehouseForm({
    warehouse,
    types,
    managers,
    facilities = [],
    categories = [],
    action,
    submitLabel,
}: WarehouseFormProps) {
    return (
        <Form
            action={action.url}
            method={action.method}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <FormSection
                        title="Identification"
                        description="How this warehouse is referred to across the ERP."
                    >
                        <Field
                            label="Code"
                            htmlFor="code"
                            required
                            error={errors.code}
                            hint="Short unique reference, e.g. WH-RM."
                        >
                            <Input
                                id="code"
                                name="code"
                                defaultValue={warehouse?.code ?? ''}
                                autoComplete="off"
                                required
                            />
                        </Field>

                        <Field
                            label="Name"
                            htmlFor="name"
                            required
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                defaultValue={warehouse?.name ?? ''}
                                required
                            />
                        </Field>

                        <Field
                            label="Facility"
                            htmlFor="facility_id"
                            error={errors.facility_id}
                            hint="The site this store belongs to."
                        >
                            <Select
                                name="facility_id"
                                defaultValue={
                                    warehouse?.facility_id
                                        ? String(warehouse.facility_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="facility_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a facility" />
                                </SelectTrigger>
                                <SelectContent>
                                    {facilities.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={String(f.value)}
                                        >
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Store category"
                            htmlFor="store_category_id"
                            error={errors.store_category_id}
                            hint="Sets what the store holds; the type below follows it."
                        >
                            <Select
                                name="store_category_id"
                                defaultValue={
                                    warehouse?.store_category_id
                                        ? String(warehouse.store_category_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="store_category_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Choose a category" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((c) => (
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
                            label="Type"
                            htmlFor="type"
                            required
                            error={errors.type}
                        >
                            <Select
                                name="type"
                                defaultValue={warehouse?.type ?? 'general'}
                            >
                                <SelectTrigger id="type" className="w-full">
                                    <SelectValue placeholder="Select a type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {types.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={String(type.value)}
                                        >
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Manager"
                            htmlFor="manager_id"
                            error={errors.manager_id}
                        >
                            <Select
                                name="manager_id"
                                defaultValue={
                                    warehouse?.manager_id
                                        ? String(warehouse.manager_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="manager_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Unassigned" />
                                </SelectTrigger>
                                <SelectContent>
                                    {managers.map((manager) => (
                                        <SelectItem
                                            key={manager.value}
                                            value={String(manager.value)}
                                        >
                                            {manager.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Address"
                        description="Used on purchase orders and delivery documents."
                    >
                        <Field
                            label="Address line 1"
                            htmlFor="address_line_1"
                            error={errors.address_line_1}
                        >
                            <Input
                                id="address_line_1"
                                name="address_line_1"
                                defaultValue={warehouse?.address_line_1 ?? ''}
                            />
                        </Field>

                        <Field
                            label="Address line 2"
                            htmlFor="address_line_2"
                            error={errors.address_line_2}
                        >
                            <Input
                                id="address_line_2"
                                name="address_line_2"
                                defaultValue={warehouse?.address_line_2 ?? ''}
                            />
                        </Field>

                        <Field label="City" htmlFor="city" error={errors.city}>
                            <Input
                                id="city"
                                name="city"
                                defaultValue={warehouse?.city ?? ''}
                            />
                        </Field>

                        <Field
                            label="State"
                            htmlFor="state"
                            error={errors.state}
                        >
                            <Input
                                id="state"
                                name="state"
                                defaultValue={warehouse?.state ?? ''}
                            />
                        </Field>

                        <Field
                            label="PIN code"
                            htmlFor="pincode"
                            error={errors.pincode}
                        >
                            <Input
                                id="pincode"
                                name="pincode"
                                defaultValue={warehouse?.pincode ?? ''}
                            />
                        </Field>

                        <Field
                            label="GSTIN"
                            htmlFor="gstin"
                            error={errors.gstin}
                            hint="15 characters, if this location is separately registered."
                        >
                            <Input
                                id="gstin"
                                name="gstin"
                                defaultValue={warehouse?.gstin ?? ''}
                                maxLength={15}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Behaviour">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_quarantine"
                                name="is_quarantine"
                                value="1"
                                defaultChecked={
                                    warehouse?.is_quarantine ?? false
                                }
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_quarantine">
                                    Quarantine store
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Stock held here is on the books but cannot
                                    be issued to production or sales until
                                    quality control releases it.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_active"
                                name="is_active"
                                value="1"
                                defaultChecked={warehouse?.is_active ?? true}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_active">Active</Label>
                                <p className="text-muted-foreground text-xs">
                                    Inactive warehouses stay in reports but
                                    cannot receive new stock.
                                </p>
                            </div>
                        </div>
                    </FormSection>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
