import { router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { qty } from '@/lib/stock';
import packaging from '@/routes/products/packaging';
import type { ProductPackagingLine, SelectOption } from '@/types';

/**
 * A product's packaging list: what each unit sold is packed in.
 *
 * Planning multiplies these by the number of units a batch fills to raise
 * the packaging store's material request.
 */
export function PackagingBom({
    productId,
    lines,
    options,
    canEdit,
}: {
    productId: number;
    lines: ProductPackagingLine[];
    options: SelectOption[];
    canEdit: boolean;
}) {
    const form = useForm({
        packaging_material_id: '',
        quantity_per_unit: '1',
        notes: '',
    });

    const used = new Set(lines.map((l) => l.packaging_material_id));
    const available = options.filter((o) => !used.has(Number(o.value)));

    return (
        <section className="bg-card rounded-xl border">
            <div className="border-b px-5 py-4">
                <h2 className="font-semibold">Packaging per unit</h2>
                <p className="text-muted-foreground text-sm">
                    Everything one unit of this product is packed in. Use a
                    fraction for shared items — 0.01 for a carton that holds
                    100.
                </p>
            </div>

            {lines.length === 0 ? (
                <p className="text-muted-foreground px-5 py-4 text-sm">
                    No packaging listed yet. Production plans cannot raise a
                    packaging request until it is.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Packaging material</TableHead>
                            <TableHead className="text-right">
                                Per unit
                            </TableHead>
                            <TableHead>Notes</TableHead>
                            {canEdit && <TableHead className="w-12" />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>
                                    <span className="font-medium">
                                        {line.name}
                                    </span>
                                    <span className="text-muted-foreground ml-2 text-xs">
                                        {line.code}
                                    </span>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {qty(line.quantity_per_unit)}{' '}
                                    {line.uom ?? ''}
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {line.notes ?? '—'}
                                </TableCell>
                                {canEdit && (
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-muted-foreground"
                                            onClick={() =>
                                                router.delete(
                                                    packaging.destroy({
                                                        product: productId,
                                                        line: line.id,
                                                    }).url,
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </TableCell>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}

            {canEdit && (
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(packaging.store(productId).url, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                    className="flex flex-wrap items-end gap-3 border-t px-5 py-4"
                >
                    <div className="min-w-64 flex-1 space-y-1.5">
                        <span className="text-sm font-medium">
                            Add packaging
                        </span>
                        <Select
                            value={form.data.packaging_material_id}
                            onValueChange={(v) =>
                                form.setData('packaging_material_id', v)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Choose a packaging material" />
                            </SelectTrigger>
                            <SelectContent>
                                {available.map((o) => (
                                    <SelectItem
                                        key={o.value}
                                        value={String(o.value)}
                                    >
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            message={form.errors.packaging_material_id}
                        />
                    </div>
                    <div className="w-32 space-y-1.5">
                        <span className="text-sm font-medium">Per unit</span>
                        <Input
                            inputMode="decimal"
                            value={form.data.quantity_per_unit}
                            onChange={(e) =>
                                form.setData(
                                    'quantity_per_unit',
                                    e.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.quantity_per_unit} />
                    </div>
                    <div className="min-w-48 flex-1 space-y-1.5">
                        <span className="text-sm font-medium">Notes</span>
                        <Input
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                            placeholder="optional"
                        />
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={
                            form.processing || !form.data.packaging_material_id
                        }
                    >
                        <Plus className="size-4" />
                        Add
                    </Button>
                </form>
            )}
        </section>
    );
}
