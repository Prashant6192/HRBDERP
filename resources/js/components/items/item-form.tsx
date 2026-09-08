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
import type { Item, SelectOption, UomOption } from '@/types';

type ItemFormProps = {
    item?: Item;
    itemType: string;
    categories: SelectOption[];
    uoms: UomOption[];
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
};

/**
 * The shared master-data form for raw materials, packaging and products.
 *
 * The three modules genuinely differ — only a product has an MRP, only a
 * liquid raw material has a density — so the type decides which sections
 * appear. The parts they share are written once.
 *
 * Numbers are plain text inputs with `inputMode="decimal"` rather than
 * `type="number"`, so the value reaches the server as the digits that were
 * typed. The server stores it in a NUMERIC column; nothing is rounded through
 * a float on the way.
 */
export function ItemForm({
    item,
    itemType,
    categories,
    uoms,
    action,
    submitLabel,
}: ItemFormProps) {
    const isProduct = itemType === 'finished_good';
    const isRawMaterial = itemType === 'raw_material';

    // A pack unit has no size of its own, so offering it as an item's stock
    // unit would produce quantities the conversion service refuses to convert.
    const stockUomOptions = uoms.filter((uom) => !uom.requires_item_factor);

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
                            hint="Unique across all items."
                        >
                            <Input
                                id="code"
                                name="code"
                                defaultValue={item?.code ?? ''}
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
                                defaultValue={item?.name ?? ''}
                                required
                            />
                        </Field>

                        <Field
                            label="Category"
                            htmlFor="category_id"
                            error={errors.category_id}
                        >
                            <Select
                                name="category_id"
                                defaultValue={
                                    item?.category_id
                                        ? String(item.category_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="category_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Uncategorised" />
                                </SelectTrigger>
                                <SelectContent>
                                    {categories.map((category) => (
                                        <SelectItem
                                            key={category.value}
                                            value={String(category.value)}
                                        >
                                            {category.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Description"
                            htmlFor="description"
                            error={errors.description}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="description"
                                name="description"
                                defaultValue={item?.description ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection
                        title="Units"
                        description="Stock for this item is held and reported in its stock unit. Receipts in any other unit are converted to it."
                    >
                        <Field
                            label="Stock unit"
                            htmlFor="stock_uom_id"
                            required
                            error={errors.stock_uom_id}
                        >
                            <Select
                                name="stock_uom_id"
                                defaultValue={
                                    item?.stock_uom_id
                                        ? String(item.stock_uom_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="stock_uom_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Select a unit" />
                                </SelectTrigger>
                                <SelectContent>
                                    {stockUomOptions.map((uom) => (
                                        <SelectItem
                                            key={uom.value}
                                            value={String(uom.value)}
                                        >
                                            {uom.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        <Field
                            label="Purchase unit"
                            htmlFor="purchase_uom_id"
                            error={errors.purchase_uom_id}
                            hint="If this item is normally bought in a different unit."
                        >
                            <Select
                                name="purchase_uom_id"
                                defaultValue={
                                    item?.purchase_uom_id
                                        ? String(item.purchase_uom_id)
                                        : undefined
                                }
                            >
                                <SelectTrigger
                                    id="purchase_uom_id"
                                    className="w-full"
                                >
                                    <SelectValue placeholder="Same as stock unit" />
                                </SelectTrigger>
                                <SelectContent>
                                    {uoms.map((uom) => (
                                        <SelectItem
                                            key={uom.value}
                                            value={String(uom.value)}
                                        >
                                            {uom.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>

                        {isRawMaterial && (
                            <Field
                                label="Density (g/ml)"
                                htmlFor="density_g_per_ml"
                                error={errors.density_g_per_ml}
                                hint="Only needed where this material is bought by weight and dosed by volume, or the reverse."
                            >
                                <Input
                                    id="density_g_per_ml"
                                    name="density_g_per_ml"
                                    inputMode="decimal"
                                    defaultValue={item?.density_g_per_ml ?? ''}
                                />
                            </Field>
                        )}
                    </FormSection>

                    {isProduct && (
                        <FormSection title="Product details">
                            <Field
                                label="Brand"
                                htmlFor="brand"
                                error={errors.brand}
                            >
                                <Input
                                    id="brand"
                                    name="brand"
                                    defaultValue={item?.brand ?? ''}
                                />
                            </Field>

                            <Field
                                label="MRP (₹)"
                                htmlFor="mrp"
                                error={errors.mrp}
                            >
                                <Input
                                    id="mrp"
                                    name="mrp"
                                    inputMode="decimal"
                                    defaultValue={item?.mrp ?? ''}
                                />
                            </Field>

                            <Field
                                label="Net content"
                                htmlFor="net_content"
                                error={errors.net_content}
                                hint="The quantity printed on the pack, e.g. 200."
                            >
                                <Input
                                    id="net_content"
                                    name="net_content"
                                    inputMode="decimal"
                                    defaultValue={item?.net_content ?? ''}
                                />
                            </Field>

                            <Field
                                label="Net content unit"
                                htmlFor="net_content_uom_id"
                                error={errors.net_content_uom_id}
                            >
                                <Select
                                    name="net_content_uom_id"
                                    defaultValue={
                                        item?.net_content_uom_id
                                            ? String(item.net_content_uom_id)
                                            : undefined
                                    }
                                >
                                    <SelectTrigger
                                        id="net_content_uom_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a unit" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {stockUomOptions.map((uom) => (
                                            <SelectItem
                                                key={uom.value}
                                                value={String(uom.value)}
                                            >
                                                {uom.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>

                            <Field
                                label="Barcode"
                                htmlFor="barcode"
                                error={errors.barcode}
                            >
                                <Input
                                    id="barcode"
                                    name="barcode"
                                    defaultValue={item?.barcode ?? ''}
                                />
                            </Field>
                        </FormSection>
                    )}

                    <FormSection title="Commercial">
                        <Field
                            label="HSN code"
                            htmlFor="hsn_code"
                            error={errors.hsn_code}
                        >
                            <Input
                                id="hsn_code"
                                name="hsn_code"
                                defaultValue={item?.hsn_code ?? ''}
                            />
                        </Field>

                        <Field
                            label="GST rate (%)"
                            htmlFor="gst_rate"
                            error={errors.gst_rate}
                        >
                            <Input
                                id="gst_rate"
                                name="gst_rate"
                                inputMode="decimal"
                                defaultValue={item?.gst_rate ?? ''}
                            />
                        </Field>

                        <Field
                            label="Standard cost (₹ per stock unit)"
                            htmlFor="standard_cost"
                            error={errors.standard_cost}
                            hint="The current cost. Costs already used by a manufactured batch are never rewritten from here."
                        >
                            <Input
                                id="standard_cost"
                                name="standard_cost"
                                inputMode="decimal"
                                defaultValue={item?.standard_cost ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Stock control">
                        <Field
                            label="Reorder level"
                            htmlFor="reorder_level"
                            error={errors.reorder_level}
                        >
                            <Input
                                id="reorder_level"
                                name="reorder_level"
                                inputMode="decimal"
                                defaultValue={item?.reorder_level ?? ''}
                            />
                        </Field>

                        <Field
                            label="Minimum stock"
                            htmlFor="minimum_stock"
                            error={errors.minimum_stock}
                        >
                            <Input
                                id="minimum_stock"
                                name="minimum_stock"
                                inputMode="decimal"
                                defaultValue={item?.minimum_stock ?? ''}
                            />
                        </Field>

                        <Field
                            label="Maximum stock"
                            htmlFor="maximum_stock"
                            error={errors.maximum_stock}
                        >
                            <Input
                                id="maximum_stock"
                                name="maximum_stock"
                                inputMode="decimal"
                                defaultValue={item?.maximum_stock ?? ''}
                            />
                        </Field>

                        <Field
                            label="Lead time (days)"
                            htmlFor="lead_time_days"
                            error={errors.lead_time_days}
                        >
                            <Input
                                id="lead_time_days"
                                name="lead_time_days"
                                inputMode="numeric"
                                defaultValue={item?.lead_time_days ?? ''}
                            />
                        </Field>

                        <Field
                            label="Shelf life (days)"
                            htmlFor="shelf_life_days"
                            error={errors.shelf_life_days}
                        >
                            <Input
                                id="shelf_life_days"
                                name="shelf_life_days"
                                inputMode="numeric"
                                defaultValue={item?.shelf_life_days ?? ''}
                            />
                        </Field>
                    </FormSection>

                    <FormSection title="Handling">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_batch_tracked"
                                name="is_batch_tracked"
                                value="1"
                                defaultChecked={item?.is_batch_tracked ?? true}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_batch_tracked">
                                    Batch tracked
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Stock is held against lot numbers, which is
                                    what makes a finished batch traceable back
                                    to its inputs.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="requires_qc"
                                name="requires_qc"
                                value="1"
                                defaultChecked={item?.requires_qc ?? false}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="requires_qc">
                                    Requires QC release
                                </Label>
                                <p className="text-muted-foreground text-xs">
                                    Received stock is quarantined until quality
                                    control approves it.
                                </p>
                            </div>
                        </div>

                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_active"
                                name="is_active"
                                value="1"
                                defaultChecked={item?.is_active ?? true}
                            />
                            <div className="space-y-1">
                                <Label htmlFor="is_active">Active</Label>
                                <p className="text-muted-foreground text-xs">
                                    Inactive items stay in history but cannot be
                                    used on new documents.
                                </p>
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
