import { Plus } from 'lucide-react';
import { useState } from 'react';
import { Field } from '@/components/form-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { quick as quickPackaging } from '@/routes/packaging-materials';
import { quick as quickRawMaterial } from '@/routes/raw-materials';
import type { SelectOption } from '@/types';

export type QuickItem = SelectOption & {
    type: string;
    stock_uom_id: number;
    stock_uom: string | null;
    requires_qc: boolean;
    shelf_life_days: number | null;
};

type Suggested = {
    name?: string | null;
    hsn?: string | null;
    uom_id?: number | null;
    quantity?: string | null;
    rate?: string | null;
};

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * A material that is not on file yet, created from the bill line without
 * leaving the receipt. The bill gives the name, HSN, unit and rate; the
 * reorder figures default to one delivery's worth and can be changed
 * on the material's page later.
 */
export function ItemQuickAdd({
    suggested,
    uoms,
    onAdded,
    trigger,
}: {
    suggested?: Suggested | null;
    uoms: (SelectOption & { dimension?: string })[];
    onAdded: (item: QuickItem) => void;
    trigger?: React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [data, setData] = useState({
        kind: 'raw_material' as 'raw_material' | 'packaging_material',
        code: '',
        name: suggested?.name ?? '',
        inci_name: '',
        hsn_code: suggested?.hsn ?? '',
        stock_uom_id: suggested?.uom_id ? String(suggested.uom_id) : '',
        requires_qc: true,
        shelf_life_days: '',
        reorder_level: suggested?.quantity ?? '',
        minimum_stock: '0',
        standard_cost: suggested?.rate ?? '',
    });

    const set = (patch: Partial<typeof data>) =>
        setData((d) => ({ ...d, ...patch }));

    const submit = async () => {
        setBusy(true);
        setErrors({});
        const url =
            data.kind === 'packaging_material'
                ? quickPackaging().url
                : quickRawMaterial().url;
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: JSON.stringify({
                    code: data.code || null,
                    name: data.name,
                    inci_name: data.inci_name || null,
                    hsn_code: data.hsn_code || null,
                    stock_uom_id: data.stock_uom_id
                        ? Number(data.stock_uom_id)
                        : null,
                    requires_qc: data.requires_qc,
                    shelf_life_days: data.shelf_life_days
                        ? Number(data.shelf_life_days)
                        : null,
                    reorder_level: data.reorder_level,
                    minimum_stock: data.minimum_stock,
                    standard_cost: data.standard_cost || null,
                }),
            });
            const body = (await response.json()) as Partial<QuickItem> & {
                errors?: Record<string, string[]>;
                message?: string;
            };
            if (!response.ok) {
                const flat: Record<string, string> = {};
                for (const [k, v] of Object.entries(body.errors ?? {})) {
                    flat[k] = v[0];
                }
                if (Object.keys(flat).length === 0) {
                    flat.name = body.message ?? 'Could not add the material.';
                }
                setErrors(flat);
                return;
            }
            if (body.value !== undefined && body.label !== undefined) {
                onAdded(body as QuickItem);
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
                        New material
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Add a material from the bill</DialogTitle>
                    <DialogDescription>
                        Nothing on file matches this line. Add it now; it goes
                        on the receipt, through QC, and into the store. Density,
                        category and the rest can be filled in on its page
                        later.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Kind" htmlFor="iq-kind" required>
                        <Select
                            value={data.kind}
                            onValueChange={(v) =>
                                set({ kind: v as typeof data.kind })
                            }
                        >
                            <SelectTrigger id="iq-kind" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="raw_material">
                                    Raw material
                                </SelectItem>
                                <SelectItem value="packaging_material">
                                    Packaging material
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                    <Field
                        label="Code"
                        htmlFor="iq-code"
                        hint="Blank: the next free code."
                        error={errors.code}
                    >
                        <Input
                            id="iq-code"
                            value={data.code}
                            onChange={(e) => set({ code: e.target.value })}
                            placeholder="RM-1042"
                        />
                    </Field>
                    <Field
                        label="Name"
                        htmlFor="iq-name"
                        required
                        error={errors.name}
                        className="sm:col-span-2"
                    >
                        <Input
                            id="iq-name"
                            value={data.name}
                            onChange={(e) => set({ name: e.target.value })}
                        />
                    </Field>
                    <Field
                        label="INCI name"
                        htmlFor="iq-inci"
                        error={errors.inci_name}
                    >
                        <Input
                            id="iq-inci"
                            value={data.inci_name}
                            onChange={(e) => set({ inci_name: e.target.value })}
                        />
                    </Field>
                    <Field label="HSN" htmlFor="iq-hsn" error={errors.hsn_code}>
                        <Input
                            id="iq-hsn"
                            value={data.hsn_code}
                            onChange={(e) => set({ hsn_code: e.target.value })}
                        />
                    </Field>
                    <Field
                        label="Stock unit"
                        htmlFor="iq-uom"
                        required
                        error={errors.stock_uom_id}
                    >
                        <Select
                            value={data.stock_uom_id}
                            onValueChange={(v) => set({ stock_uom_id: v })}
                        >
                            <SelectTrigger id="iq-uom" className="w-full">
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
                    </Field>
                    <Field
                        label="Shelf life (days)"
                        htmlFor="iq-shelf"
                        error={errors.shelf_life_days}
                    >
                        <Input
                            id="iq-shelf"
                            inputMode="numeric"
                            value={data.shelf_life_days}
                            onChange={(e) =>
                                set({ shelf_life_days: e.target.value })
                            }
                            placeholder="730"
                        />
                    </Field>
                    <Field
                        label="Reorder level"
                        htmlFor="iq-reorder"
                        required
                        hint="Defaults to this delivery's quantity."
                        error={errors.reorder_level}
                    >
                        <Input
                            id="iq-reorder"
                            inputMode="decimal"
                            value={data.reorder_level}
                            onChange={(e) =>
                                set({ reorder_level: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="Minimum stock"
                        htmlFor="iq-min"
                        required
                        error={errors.minimum_stock}
                    >
                        <Input
                            id="iq-min"
                            inputMode="decimal"
                            value={data.minimum_stock}
                            onChange={(e) =>
                                set({ minimum_stock: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="Standard cost per unit"
                        htmlFor="iq-cost"
                        error={errors.standard_cost}
                    >
                        <Input
                            id="iq-cost"
                            inputMode="decimal"
                            value={data.standard_cost}
                            onChange={(e) =>
                                set({ standard_cost: e.target.value })
                            }
                        />
                    </Field>
                    <label className="flex items-center gap-2 self-end text-sm">
                        <Checkbox
                            checked={data.requires_qc}
                            onCheckedChange={(v) =>
                                set({ requires_qc: v === true })
                            }
                        />
                        Needs QC before use
                    </label>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setOpen(false)}
                        disabled={busy}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        onClick={() => void submit()}
                        disabled={
                            busy ||
                            data.name.trim() === '' ||
                            data.stock_uom_id === ''
                        }
                    >
                        {busy ? 'Adding…' : 'Add and use on this line'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
