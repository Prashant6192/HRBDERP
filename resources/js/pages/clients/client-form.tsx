import { Form } from '@inertiajs/react';
import { Field, FormSection } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Client } from '@/types';

export function ClientForm({
    client,
    nextCode,
    action,
    submitLabel,
}: {
    client?: Client;
    nextCode?: string;
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
                    <FormSection
                        title="Client"
                        description={
                            client
                                ? `Client code ${client.code}.`
                                : `The next client code is ${nextCode ?? 'TP-…'}; it is given on save.`
                        }
                    >
                        <Field
                            label="Client name"
                            htmlFor="name"
                            required
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                name="name"
                                defaultValue={client?.name ?? ''}
                                required
                            />
                        </Field>
                        <Field
                            label="Company / legal name"
                            htmlFor="legal_name"
                            error={errors.legal_name}
                        >
                            <Input
                                id="legal_name"
                                name="legal_name"
                                defaultValue={client?.legal_name ?? ''}
                            />
                        </Field>
                        <Field
                            label="GSTIN"
                            htmlFor="gstin"
                            error={errors.gstin}
                            hint="15 characters. Two clients cannot share one."
                        >
                            <Input
                                id="gstin"
                                name="gstin"
                                maxLength={15}
                                defaultValue={client?.gstin ?? ''}
                                className="font-mono uppercase"
                            />
                        </Field>
                        <Field label="PAN" htmlFor="pan" error={errors.pan}>
                            <Input
                                id="pan"
                                name="pan"
                                maxLength={10}
                                defaultValue={client?.pan ?? ''}
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
                                defaultValue={client?.contact_person ?? ''}
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
                                defaultValue={client?.phone ?? ''}
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
                                defaultValue={client?.email ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Billing address">
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
                                    client?.billing_address_line_1 ?? ''
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
                                    client?.billing_address_line_2 ?? ''
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
                                defaultValue={client?.billing_city ?? ''}
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
                                defaultValue={client?.billing_state ?? ''}
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
                                defaultValue={client?.billing_pincode ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Shipping address"
                        description="Where their finished goods go. Leave blank if it is the billing address."
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
                                    client?.shipping_address_line_1 ?? ''
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
                                    client?.shipping_address_line_2 ?? ''
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
                                defaultValue={client?.shipping_city ?? ''}
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
                                defaultValue={client?.shipping_state ?? ''}
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
                                defaultValue={client?.shipping_pincode ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Terms">
                        <Field
                            label="Payment terms (days)"
                            htmlFor="payment_terms_days"
                            error={errors.payment_terms_days}
                        >
                            <Input
                                id="payment_terms_days"
                                name="payment_terms_days"
                                inputMode="numeric"
                                defaultValue={client?.payment_terms_days ?? ''}
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
                                defaultValue={client?.credit_limit ?? ''}
                            />
                        </Field>
                        <Field
                            label="Agreement / contract ref."
                            htmlFor="agreement_ref"
                            error={errors.agreement_ref}
                        >
                            <Input
                                id="agreement_ref"
                                name="agreement_ref"
                                defaultValue={client?.agreement_ref ?? ''}
                            />
                        </Field>
                        <Field
                            label="Agreement valid until"
                            htmlFor="agreement_expires_at"
                            error={errors.agreement_expires_at}
                        >
                            <Input
                                id="agreement_expires_at"
                                name="agreement_expires_at"
                                type="date"
                                defaultValue={
                                    client?.agreement_expires_at ?? ''
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="notes"
                            error={errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="notes"
                                name="notes"
                                defaultValue={client?.notes ?? ''}
                            />
                        </Field>
                        <div className="flex items-center gap-2 sm:col-span-2">
                            <input type="hidden" name="is_active" value="0" />
                            <Checkbox
                                id="is_active"
                                name="is_active"
                                value="1"
                                defaultChecked={client?.is_active ?? true}
                            />
                            <Label htmlFor="is_active">Active client</Label>
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
