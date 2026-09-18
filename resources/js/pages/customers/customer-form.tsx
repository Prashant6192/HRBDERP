import { Form } from '@inertiajs/react';
import { useState } from 'react';
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
import type { Customer, SelectOption } from '@/types';

const NONE = '__none__';

export function CustomerForm({
    customer,
    nextCode,
    kinds,
    clients,
    action,
    submitLabel,
}: {
    customer?: Customer;
    nextCode?: string;
    kinds: SelectOption[];
    clients: SelectOption[];
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
}) {
    const [kind, setKind] = useState(customer?.kind ?? 'marketplace');
    const [clientId, setClientId] = useState(
        customer?.client_id ? String(customer.client_id) : '',
    );

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
                        title="Customer"
                        description={
                            customer
                                ? `Customer code ${customer.code}.`
                                : `The next customer code is ${nextCode ?? 'CUS-…'}; it is given on save.`
                        }
                    >
                        <Field
                            label="Name"
                            htmlFor="name"
                            required
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                defaultValue={customer?.name ?? ''}
                                required
                            />
                        </Field>
                        <Field
                            label="Legal name (on the invoice)"
                            htmlFor="legal_name"
                            error={errors.legal_name}
                        >
                            <Input
                                id="legal_name"
                                name="legal_name"
                                defaultValue={customer?.legal_name ?? ''}
                            />
                        </Field>
                        <Field
                            label="Kind"
                            htmlFor="kind"
                            required
                            error={errors.kind}
                        >
                            <input type="hidden" name="kind" value={kind} />
                            <Select value={kind} onValueChange={setKind}>
                                <SelectTrigger id="kind" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {kinds.map((k) => (
                                        <SelectItem
                                            key={k.value}
                                            value={String(k.value)}
                                        >
                                            {k.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Contract client"
                            htmlFor="client_id"
                            error={errors.client_id}
                            hint="Link a contract client here and their batches can be dispatched to this customer. Leave blank for buyers of our own goods."
                        >
                            <input
                                type="hidden"
                                name="client_id"
                                value={clientId}
                            />
                            <Select
                                value={clientId || NONE}
                                onValueChange={(v) =>
                                    setClientId(v === NONE ? '' : v)
                                }
                            >
                                <SelectTrigger
                                    id="client_id"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>
                                        Not a contract client
                                    </SelectItem>
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
                            label="GSTIN"
                            htmlFor="gstin"
                            error={errors.gstin}
                            hint="15 characters. Leave blank for an unregistered (B2C) buyer; e-invoicing then does not apply."
                        >
                            <Input
                                id="gstin"
                                name="gstin"
                                maxLength={15}
                                defaultValue={customer?.gstin ?? ''}
                                className="font-mono uppercase"
                            />
                        </Field>
                        <Field label="PAN" htmlFor="pan" error={errors.pan}>
                            <Input
                                id="pan"
                                name="pan"
                                maxLength={10}
                                defaultValue={customer?.pan ?? ''}
                                className="font-mono uppercase"
                            />
                        </Field>
                        <Field
                            label="Contact person"
                            htmlFor="contact_person"
                            error={errors.contact_person}
                        >
                            <Input
                                id="contact_person"
                                name="contact_person"
                                defaultValue={customer?.contact_person ?? ''}
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
                                defaultValue={customer?.phone ?? ''}
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
                                defaultValue={customer?.email ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Billing address"
                        description="Printed as the buyer on the invoice and e-invoice."
                    >
                        <Field
                            label="Address line 1"
                            htmlFor="billing_address_line_1"
                            error={errors.billing_address_line_1}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="billing_address_line_1"
                                name="billing_address_line_1"
                                defaultValue={
                                    customer?.billing_address_line_1 ?? ''
                                }
                            />
                        </Field>
                        <Field
                            label="Address line 2"
                            htmlFor="billing_address_line_2"
                            error={errors.billing_address_line_2}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="billing_address_line_2"
                                name="billing_address_line_2"
                                defaultValue={
                                    customer?.billing_address_line_2 ?? ''
                                }
                            />
                        </Field>
                        <Field
                            label="City"
                            htmlFor="billing_city"
                            error={errors.billing_city}
                        >
                            <Input
                                id="billing_city"
                                name="billing_city"
                                defaultValue={customer?.billing_city ?? ''}
                            />
                        </Field>
                        <Field
                            label="State"
                            htmlFor="billing_state"
                            error={errors.billing_state}
                        >
                            <Input
                                id="billing_state"
                                name="billing_state"
                                defaultValue={customer?.billing_state ?? ''}
                            />
                        </Field>
                        <Field
                            label="PIN code"
                            htmlFor="billing_pincode"
                            error={errors.billing_pincode}
                        >
                            <Input
                                id="billing_pincode"
                                name="billing_pincode"
                                defaultValue={customer?.billing_pincode ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Shipping address"
                        description="Where the goods usually go, when it differs from the billing address. It can still be changed on each dispatch."
                    >
                        <Field
                            label="Address line 1"
                            htmlFor="shipping_address_line_1"
                            error={errors.shipping_address_line_1}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="shipping_address_line_1"
                                name="shipping_address_line_1"
                                defaultValue={
                                    customer?.shipping_address_line_1 ?? ''
                                }
                            />
                        </Field>
                        <Field
                            label="Address line 2"
                            htmlFor="shipping_address_line_2"
                            error={errors.shipping_address_line_2}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="shipping_address_line_2"
                                name="shipping_address_line_2"
                                defaultValue={
                                    customer?.shipping_address_line_2 ?? ''
                                }
                            />
                        </Field>
                        <Field
                            label="City"
                            htmlFor="shipping_city"
                            error={errors.shipping_city}
                        >
                            <Input
                                id="shipping_city"
                                name="shipping_city"
                                defaultValue={customer?.shipping_city ?? ''}
                            />
                        </Field>
                        <Field
                            label="State"
                            htmlFor="shipping_state"
                            error={errors.shipping_state}
                        >
                            <Input
                                id="shipping_state"
                                name="shipping_state"
                                defaultValue={customer?.shipping_state ?? ''}
                            />
                        </Field>
                        <Field
                            label="PIN code"
                            htmlFor="shipping_pincode"
                            error={errors.shipping_pincode}
                        >
                            <Input
                                id="shipping_pincode"
                                name="shipping_pincode"
                                defaultValue={customer?.shipping_pincode ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Notes">
                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="notes"
                                name="notes"
                                defaultValue={customer?.notes ?? ''}
                            />
                        </Field>
                        <div className="flex items-center gap-2 sm:col-span-2">
                            <input type="hidden" name="is_active" value="0" />
                            <Checkbox
                                id="is_active"
                                name="is_active"
                                value="1"
                                defaultChecked={customer?.is_active ?? true}
                            />
                            <Label htmlFor="is_active">Active customer</Label>
                        </div>
                    </FormSection>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
