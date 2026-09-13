import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { quick } from '@/routes/vendors';
import type { SelectOption } from '@/types';

type Suggested = {
    name?: string | null;
    gstin?: string | null;
    address?: string | null;
    phone?: string | null;
    email?: string | null;
};

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Add a vendor without leaving the receipt: name and GSTIN now, the rest
 * on the vendor's own page later.
 */
export function VendorQuickAdd({
    suggested,
    onAdded,
    trigger,
}: {
    suggested?: Suggested | null;
    onAdded: (vendor: SelectOption) => void;
    trigger?: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [data, setData] = useState({
        name: suggested?.name ?? '',
        gstin: suggested?.gstin ?? '',
        phone: suggested?.phone ?? '',
        email: suggested?.email ?? '',
        address_line_1: suggested?.address ?? '',
        city: '',
        supply_type: 'mixed',
    });

    const submit = async () => {
        setBusy(true);
        setErrors({});
        try {
            const response = await fetch(quick().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: JSON.stringify({
                    ...data,
                    gstin: data.gstin || null,
                    phone: data.phone || null,
                    email: data.email || null,
                    address_line_1: data.address_line_1 || null,
                    city: data.city || null,
                }),
            });
            const body = (await response.json()) as {
                value?: number;
                label?: string;
                errors?: Record<string, string[]>;
                message?: string;
            };
            if (!response.ok) {
                const flat: Record<string, string> = {};
                for (const [k, v] of Object.entries(body.errors ?? {})) {
                    flat[k] = v[0];
                }
                if (Object.keys(flat).length === 0) {
                    flat.name = body.message ?? 'Could not add the vendor.';
                }
                setErrors(flat);
                return;
            }
            if (body.value !== undefined && body.label !== undefined) {
                onAdded({ value: body.value, label: body.label });
                setOpen(false);
            }
        } catch {
            setErrors({ name: 'Could not reach the server. Try again.' });
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button type="button" variant="outline" size="sm">
                        <Plus className="size-4" />
                        Add vendor
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add a vendor</DialogTitle>
                    <DialogDescription>
                        Enough to book this receipt. Payment terms and the rest
                        can be filled in on the vendor&rsquo;s page later.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Vendor name"
                        htmlFor="qa-name"
                        required
                        error={errors.name}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="qa-name"
                            value={data.name}
                            onChange={(e) =>
                                setData({ ...data, name: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="GSTIN"
                        htmlFor="qa-gstin"
                        error={errors.gstin}
                    >
                        <Input
                            id="qa-gstin"
                            maxLength={15}
                            value={data.gstin}
                            onChange={(e) =>
                                setData({
                                    ...data,
                                    gstin: e.target.value.toUpperCase(),
                                })
                            }
                        />
                    </Field>
                    <Field
                        label="Supplies"
                        htmlFor="qa-type"
                        error={errors.supply_type}
                    >
                        <Select
                            value={data.supply_type}
                            onValueChange={(v) =>
                                setData({ ...data, supply_type: v })
                            }
                        >
                            <SelectTrigger id="qa-type" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="raw_material">
                                    Raw materials
                                </SelectItem>
                                <SelectItem value="packaging">
                                    Packaging
                                </SelectItem>
                                <SelectItem value="services">
                                    Services
                                </SelectItem>
                                <SelectItem value="mixed">Mixed</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Phone"
                        htmlFor="qa-phone"
                        error={errors.phone}
                    >
                        <Input
                            id="qa-phone"
                            value={data.phone}
                            onChange={(e) =>
                                setData({ ...data, phone: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="Email"
                        htmlFor="qa-email"
                        error={errors.email}
                    >
                        <Input
                            id="qa-email"
                            type="email"
                            value={data.email}
                            onChange={(e) =>
                                setData({ ...data, email: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="Address"
                        htmlFor="qa-address"
                        error={errors.address_line_1}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="qa-address"
                            value={data.address_line_1}
                            onChange={(e) =>
                                setData({
                                    ...data,
                                    address_line_1: e.target.value,
                                })
                            }
                        />
                    </Field>
                </div>
                <DialogFooter className="gap-2 sm:gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpen(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={submit}
                        disabled={busy || data.name.trim() === ''}
                    >
                        {busy ? 'Adding…' : 'Add vendor'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
