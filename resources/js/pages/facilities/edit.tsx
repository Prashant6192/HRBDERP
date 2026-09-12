import { Head, useForm } from '@inertiajs/react';
import { Field, FormSection } from '@/components/form-field';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes';
import { index, show, update } from '@/routes/facilities';
import type {
    CapabilityMeta,
    Facility,
    FacilityTypeOption,
    SelectOption,
} from '@/types';
import {
    CapabilitySwitches,
    FacilityDetailsFields,
    capabilitiesFrom,
    type Capabilities,
    type FacilityDetails,
} from './facility-form';

export default function EditFacility({
    facility,
    types,
    capabilities,
    managers,
}: {
    facility: Facility;
    types: FacilityTypeOption[];
    capabilities: CapabilityMeta[];
    managers: SelectOption[];
}) {
    const form = useForm<
        FacilityDetails &
            Capabilities & {
                opening_stock_enabled: boolean;
                is_active: boolean;
            }
    >({
        name: facility.name,
        code: facility.code,
        facility_type_id: String(facility.facility_type_id),
        manager_id: facility.manager_id ? String(facility.manager_id) : '',
        address_line_1: facility.address_line_1 ?? '',
        address_line_2: facility.address_line_2 ?? '',
        city: facility.city ?? '',
        state: facility.state ?? '',
        pincode: facility.pincode ?? '',
        country: facility.country ?? 'India',
        phone: facility.phone ?? '',
        email: facility.email ?? '',
        gstin: facility.gstin ?? '',
        notes: facility.notes ?? '',
        ...capabilitiesFrom(facility),
        opening_stock_enabled: facility.opening_stock_enabled,
        is_active: facility.is_active,
    });

    const caps = capabilitiesFrom(form.data);

    return (
        <>
            <Head title={`Edit ${facility.code}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={facility.name}
                    description="Edit the facility. Stores are added and switched off on the facility page; nothing already recorded against them changes."
                />

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(update(facility.id).url, {
                            preserveScroll: true,
                        });
                    }}
                    className="space-y-6"
                >
                    <FacilityDetailsFields
                        data={form.data}
                        setField={(k, v) =>
                            form.setData({ ...form.data, [k]: v })
                        }
                        errors={form.errors}
                        types={types}
                        managers={managers}
                    />

                    <CapabilitySwitches
                        value={caps}
                        onChange={(next) =>
                            form.setData({ ...form.data, ...next })
                        }
                        capabilities={capabilities}
                        error={form.errors.can_manufacture}
                    />

                    <FormSection
                        title="Status"
                        description="Switch the facility off rather than removing it; every record it touched stays readable."
                    >
                        <Field
                            label=""
                            htmlFor="is_active"
                            error={form.errors.is_active}
                        >
                            <label className="flex items-start gap-3">
                                <Checkbox
                                    id="is_active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(c) =>
                                        form.setData('is_active', c === true)
                                    }
                                />
                                <span className="space-y-0.5">
                                    <Label htmlFor="is_active">Active</Label>
                                    <p className="text-muted-foreground text-xs">
                                        A deactivated facility holds no stock
                                        and takes no new work.
                                    </p>
                                </span>
                            </label>
                        </Field>
                        <Field
                            label=""
                            htmlFor="opening_stock_enabled"
                            error={form.errors.opening_stock_enabled}
                        >
                            <label className="flex items-start gap-3">
                                <Checkbox
                                    id="opening_stock_enabled"
                                    checked={form.data.opening_stock_enabled}
                                    onCheckedChange={(c) =>
                                        form.setData(
                                            'opening_stock_enabled',
                                            c === true,
                                        )
                                    }
                                />
                                <span className="space-y-0.5">
                                    <Label htmlFor="opening_stock_enabled">
                                        Opening stock entry allowed
                                    </Label>
                                    <p className="text-muted-foreground text-xs">
                                        Switch off once the facility is live so
                                        stock only moves through receipts,
                                        production and transfers.
                                    </p>
                                </span>
                            </label>
                        </Field>
                    </FormSection>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            Save changes
                        </Button>
                    </div>
                </form>
            </div>
        </>
    );
}

EditFacility.layout = ({ facility }: { facility: Facility }) => ({
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Facilities & Warehouses', href: index() },
        { title: facility.name, href: show(facility.id) },
        { title: 'Edit', href: '#' },
    ],
});
