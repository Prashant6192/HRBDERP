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
import type { Vendor } from '@/types';

const SUPPLY_TYPES = [
    { value: 'raw_material', label: 'Raw materials' },
    { value: 'packaging', label: 'Packaging' },
    { value: 'services', label: 'Services' },
    { value: 'mixed', label: 'Mixed' },
];

export function VendorForm({
    vendor,
    action,
    submitLabel,
}: {
    vendor?: Vendor;
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
}) {
    return (
        <Form
            action={action.url}
            method={action.method}
            options={{ preserveScroll: true }}
            className="space-y-6"
        >
            {({ errors, processing }) => (
                <>
                    <FormSection title="Identification">
                        <Field
                            label="Code"
                            htmlFor="code"
                            required
                            error={errors.code}
                        >
                            <Input
                                id="code"
                                name="code"
                                defaultValue={vendor?.code ?? ''}
                                autoComplete="off"
                                required
                            />
                        </Field>

                        <Field
                            label="Trading name"
                            htmlFor="name"
                            required
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                defaultValue={vendor?.name ?? ''}
                                required
                            />
                        </Field>

                        <Field
                            label="Legal name"
                            htmlFor="legal_name"
                            error={errors.legal_name}
                        >
                            <Input
                                id="legal_name"
                                name="legal_name"
                                defaultValue={vendor?.legal_name ?? ''}
                            />
                        </Field>

                        <Field
                            label="Supplies"
                            htmlFor="supply_type"
                            required
                            error={errors.supply_type}
                        >
                            <Select
                                name="supply_type"
                                defaultValue={vendor?.supply_type ?? 'mixed'}
                            >
                                <SelectTrigger
                                    id="supply_type"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {SUPPLY_TYPES.map((type) => (
                                        <SelectItem
                                            key={type.value}
                                            value={type.value}
                                        >
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="GSTIN"
                            htmlFor="gstin"
                            error={errors.gstin}
                            hint="15 characters. Two vendors cannot share one."
                        >
                            <Input
                                id="gstin"
                                name="gstin"
                                defaultValue={vendor?.gstin ?? ''}
                                maxLength={15}
                            />
                        </Field>

                        <Field label="PAN" htmlFor="pan" error={errors.pan}>
                            <Input
                                id="pan"
                                name="pan"
                                defaultValue={vendor?.pan ?? ''}
                                maxLength={10}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Contact">
                        <Field
                            label="Contact person"
                            htmlFor="contact_person"
                            error={errors.contact_person}
                        >
                            <Input
                                id="contact_person"
                                name="contact_person"
                                defaultValue={vendor?.contact_person ?? ''}
                            />
                        </Field>

                        <Field
                            label="Email"
                            htmlFor="email"
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                name="email"
                                type="email"
                                defaultValue={vendor?.email ?? ''}
                            />
                        </Field>

                        <Field
                            label="Phone"
                            htmlFor="phone"
                            error={errors.phone}
                        >
                            <Input
                                id="phone"
                                name="phone"
                                defaultValue={vendor?.phone ?? ''}
                            />
                        </Field>

                        <Field
                            label="Address line 1"
                            htmlFor="address_line_1"
                            error={errors.address_line_1}
                        >
                            <Input
                                id="address_line_1"
                                name="address_line_1"
                                defaultValue={vendor?.address_line_1 ?? ''}
                            />
                        </Field>

                        <Field label="City" htmlFor="city" error={errors.city}>
                            <Input
                                id="city"
                                name="city"
                                defaultValue={vendor?.city ?? ''}
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
                                defaultValue={vendor?.state ?? ''}
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
                                defaultValue={vendor?.pincode ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Trading terms">
                        <Field
                            label="Payment terms (days)"
                            htmlFor="payment_terms_days"
                            error={errors.payment_terms_days}
                        >
                            <Input
                                id="payment_terms_days"
                                name="payment_terms_days"
                                inputMode="numeric"
                                defaultValue={vendor?.payment_terms_days ?? ''}
                            />
                        </Field>

                        <Field
                            label="Credit limit (₹)"
                            htmlFor="credit_limit"
                            error={errors.credit_limit}
                        >
                            <Input
                                id="credit_limit"
                                name="credit_limit"
                                inputMode="decimal"
                                defaultValue={vendor?.credit_limit ?? ''}
                            />
                        </Field>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_approved"
                                name="is_approved"
                                value="1"
                                defaultChecked={vendor?.is_approved ?? false}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_approved">Approved</Label>
                                <p className="text-muted-foreground text-xs">
                                    Only approved vendors can have a purchase
                                    order raised against them.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_active"
                                name="is_active"
                                value="1"
                                defaultChecked={vendor?.is_active ?? true}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_active">Active</Label>
                            </div>
                        </div>
                    </FormSection>

                    <Button type="submit" disabled={processing}>
                        {submitLabel}
                    </Button>
                </>
            )}
        </Form>
    );
}
