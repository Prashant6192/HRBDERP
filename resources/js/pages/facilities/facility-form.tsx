import { Field, FormSection } from '@/components/form-field';
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
import type {
    CapabilityMeta,
    FacilityCapabilityKey,
    FacilityTypeOption,
    SelectOption,
} from '@/types';

export type FacilityDetails = {
    name: string;
    code: string;
    facility_type_id: string;
    manager_id: string;
    address_line_1: string;
    address_line_2: string;
    city: string;
    state: string;
    pincode: string;
    country: string;
    phone: string;
    email: string;
    gstin: string;
    notes: string;
};

export type Capabilities = Record<FacilityCapabilityKey, boolean>;

export const EMPTY_DETAILS: FacilityDetails = {
    name: '',
    code: '',
    facility_type_id: '',
    manager_id: '',
    address_line_1: '',
    address_line_2: '',
    city: '',
    state: '',
    pincode: '',
    country: 'India',
    phone: '',
    email: '',
    gstin: '',
    notes: '',
};

export const CAPABILITY_KEYS: FacilityCapabilityKey[] = [
    'can_store',
    'can_receive',
    'can_qc',
    'can_manufacture',
    'can_pack',
    'can_dispatch',
    'can_return',
];

export function capabilitiesFrom(
    defaults: Partial<Record<FacilityCapabilityKey, boolean>>,
): Capabilities {
    return Object.fromEntries(
        CAPABILITY_KEYS.map((k) => [k, Boolean(defaults[k])]),
    ) as Capabilities;
}

type Errors = Partial<Record<string, string>>;

/**
 * The basic details block, shared by the wizard's first step and the
 * edit screen.
 */
export function FacilityDetailsFields({
    data,
    setField,
    errors,
    types,
    managers,
    onTypeChange,
}: {
    data: FacilityDetails;
    setField: <K extends keyof FacilityDetails>(
        key: K,
        value: FacilityDetails[K],
    ) => void;
    errors: Errors;
    types: FacilityTypeOption[];
    managers: SelectOption[];
    onTypeChange?: (type: FacilityTypeOption | undefined) => void;
}) {
    return (
        <>
            <FormSection
                title="Basic details"
                description="How the facility is named and reached."
            >
                <Field
                    label="Facility name"
                    htmlFor="name"
                    required
                    error={errors.name}
                >
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(e) => setField('name', e.target.value)}
                        placeholder="Rudrapur Manufacturing Facility"
                        required
                    />
                </Field>

                <Field
                    label="Facility code"
                    htmlFor="code"
                    error={errors.code}
                    hint="Leave blank to generate one, e.g. FAC-RDP-001."
                >
                    <Input
                        id="code"
                        value={data.code}
                        onChange={(e) =>
                            setField('code', e.target.value.toUpperCase())
                        }
                        placeholder="FAC-RDP-001"
                        autoComplete="off"
                    />
                </Field>

                <Field
                    label="Facility type"
                    htmlFor="facility_type_id"
                    required
                    error={errors.facility_type_id}
                >
                    <Select
                        value={data.facility_type_id}
                        onValueChange={(v) => {
                            setField('facility_type_id', v);
                            onTypeChange?.(
                                types.find((t) => String(t.value) === v),
                            );
                        }}
                    >
                        <SelectTrigger id="facility_type_id" className="w-full">
                            <SelectValue placeholder="Choose a type" />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((t) => (
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

                <Field
                    label="Facility manager"
                    htmlFor="manager_id"
                    error={errors.manager_id}
                >
                    <Select
                        value={data.manager_id || 'none'}
                        onValueChange={(v) =>
                            setField('manager_id', v === 'none' ? '' : v)
                        }
                    >
                        <SelectTrigger id="manager_id" className="w-full">
                            <SelectValue placeholder="Unassigned" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Unassigned</SelectItem>
                            {managers.map((m) => (
                                <SelectItem
                                    key={m.value}
                                    value={String(m.value)}
                                >
                                    {m.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </FormSection>

            <FormSection
                title="Address & contact"
                description="Printed on transfer notes and delivery documents."
            >
                <Field
                    label="Address line 1"
                    htmlFor="address_line_1"
                    error={errors.address_line_1}
                >
                    <Input
                        id="address_line_1"
                        value={data.address_line_1}
                        onChange={(e) =>
                            setField('address_line_1', e.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Address line 2"
                    htmlFor="address_line_2"
                    error={errors.address_line_2}
                >
                    <Input
                        id="address_line_2"
                        value={data.address_line_2}
                        onChange={(e) =>
                            setField('address_line_2', e.target.value)
                        }
                    />
                </Field>
                <Field label="City" htmlFor="city" error={errors.city}>
                    <Input
                        id="city"
                        value={data.city}
                        onChange={(e) => setField('city', e.target.value)}
                    />
                </Field>
                <Field label="State" htmlFor="state" error={errors.state}>
                    <Input
                        id="state"
                        value={data.state}
                        onChange={(e) => setField('state', e.target.value)}
                    />
                </Field>
                <Field
                    label="PIN code"
                    htmlFor="pincode"
                    error={errors.pincode}
                >
                    <Input
                        id="pincode"
                        value={data.pincode}
                        onChange={(e) => setField('pincode', e.target.value)}
                    />
                </Field>
                <Field label="Country" htmlFor="country" error={errors.country}>
                    <Input
                        id="country"
                        value={data.country}
                        onChange={(e) => setField('country', e.target.value)}
                    />
                </Field>
                <Field label="Phone" htmlFor="phone" error={errors.phone}>
                    <Input
                        id="phone"
                        value={data.phone}
                        onChange={(e) => setField('phone', e.target.value)}
                    />
                </Field>
                <Field label="Email" htmlFor="email" error={errors.email}>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setField('email', e.target.value)}
                    />
                </Field>
                <Field
                    label="GSTIN"
                    htmlFor="gstin"
                    error={errors.gstin}
                    hint="15 characters, if the facility is separately registered."
                >
                    <Input
                        id="gstin"
                        value={data.gstin}
                        maxLength={15}
                        onChange={(e) =>
                            setField('gstin', e.target.value.toUpperCase())
                        }
                    />
                </Field>
                <Field label="Notes" htmlFor="notes" error={errors.notes}>
                    <textarea
                        id="notes"
                        value={data.notes}
                        onChange={(e) => setField('notes', e.target.value)}
                        rows={3}
                        className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                    />
                </Field>
            </FormSection>
        </>
    );
}

/**
 * The capability switches. What a facility may do, never what it is called.
 */
export function CapabilitySwitches({
    value,
    onChange,
    capabilities,
    error,
}: {
    value: Capabilities;
    onChange: (next: Capabilities) => void;
    capabilities: CapabilityMeta[];
    error?: string;
}) {
    return (
        <FormSection
            title="Capabilities"
            description="Workflows check these switches. A manufacturing order can only be raised for a facility with Manufacturing on."
        >
            <div className="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                {capabilities.map((c) => (
                    <label
                        key={c.key}
                        htmlFor={`cap-${c.key}`}
                        className="hover:bg-muted/40 flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                    >
                        <Checkbox
                            id={`cap-${c.key}`}
                            checked={value[c.key]}
                            onCheckedChange={(checked) =>
                                onChange({
                                    ...value,
                                    [c.key]: checked === true,
                                })
                            }
                        />
                        <div className="space-y-0.5">
                            <Label htmlFor={`cap-${c.key}`}>{c.label}</Label>
                            {c.description && (
                                <p className="text-muted-foreground text-xs">
                                    {c.description}
                                </p>
                            )}
                        </div>
                    </label>
                ))}
            </div>
            {error && <p className="text-destructive text-sm">{error}</p>}
        </FormSection>
    );
}
