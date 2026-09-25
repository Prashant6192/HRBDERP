import { useForm } from '@inertiajs/react';
import { Lock, Pencil, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import openingStock from '@/routes/facilities/opening-stock';

export type BookedLine = {
    lot_id: number;
    store_id: number;
    store: string;
    item: string | null;
    item_code: string | null;
    uom: string | null;
    batch_number: string;
    manufactured_at: string | null;
    expiry_at: string | null;
    unit_cost: string | null;
    booked_quantity: string;
    quantity: string;
    corrected: boolean;
    number: string | null;
    booked_at: string | null;
    booked_by: string | null;
    locked: string | null;
};

function day(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString('en-IN', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';
}

function ChangeDialog({
    facilityId,
    line,
    onClose,
}: {
    facilityId: number;
    line: BookedLine;
    onClose: () => void;
}) {
    const form = useForm({
        quantity: line.quantity,
        batch_number: line.batch_number,
        manufactured_at: line.manufactured_at ?? '',
        expiry_at: line.expiry_at ?? '',
        unit_cost: line.unit_cost ?? '',
        reason: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(openingStock.update([facilityId, line.lot_id]).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Change opening stock</DialogTitle>
                    <DialogDescription>
                        {line.item} in {line.store}. The first figure stays in
                        the history with your correction and reason beside it.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1.5">
                            <Label htmlFor="c_quantity">
                                Quantity ({line.uom ?? 'stock unit'})
                            </Label>
                            <Input
                                id="c_quantity"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="any"
                                value={form.data.quantity}
                                onChange={(e) =>
                                    form.setData('quantity', e.target.value)
                                }
                            />
                            <InputError message={errors.quantity} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="c_rate">Rate (₹ per unit)</Label>
                            <Input
                                id="c_rate"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="any"
                                value={form.data.unit_cost}
                                onChange={(e) =>
                                    form.setData('unit_cost', e.target.value)
                                }
                            />
                            <InputError message={errors.unit_cost} />
                        </div>
                        <div className="col-span-2 space-y-1.5">
                            <Label htmlFor="c_batch">Batch number</Label>
                            <Input
                                id="c_batch"
                                value={form.data.batch_number}
                                onChange={(e) =>
                                    form.setData('batch_number', e.target.value)
                                }
                            />
                            <InputError message={errors.batch_number} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="c_mfg">Manufactured</Label>
                            <Input
                                id="c_mfg"
                                type="date"
                                value={form.data.manufactured_at}
                                onChange={(e) =>
                                    form.setData(
                                        'manufactured_at',
                                        e.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="c_exp">Expiry</Label>
                            <Input
                                id="c_exp"
                                type="date"
                                value={form.data.expiry_at}
                                onChange={(e) =>
                                    form.setData('expiry_at', e.target.value)
                                }
                            />
                        </div>
                        <div className="col-span-2 space-y-1.5">
                            <Label htmlFor="c_reason">Why is it changed?</Label>
                            <Input
                                id="c_reason"
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                                placeholder="Counted again: 480, not 500"
                            />
                            <InputError message={errors.reason} />
                        </div>
                    </div>
                    <InputError message={errors.correction} />
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                form.data.reason.trim().length < 5
                            }
                        >
                            Save change
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function RemoveDialog({
    facilityId,
    line,
    onClose,
}: {
    facilityId: number;
    line: BookedLine;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Remove this opening stock?</DialogTitle>
                    <DialogDescription>
                        {Number(line.quantity)} {line.uom} of {line.item}, batch{' '}
                        {line.batch_number}, comes out of {line.store}. The
                        booking and its removal both stay in the history.
                    </DialogDescription>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.delete(
                            openingStock.destroy([facilityId, line.lot_id]).url,
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                    className="space-y-4"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="r_reason">Why is it removed?</Label>
                        <Input
                            id="r_reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="Booked in the wrong store"
                            autoFocus
                        />
                        <InputError message={errors.reason} />
                        <InputError message={errors.correction} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                form.processing ||
                                form.data.reason.trim().length < 5
                            }
                        >
                            Remove
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * What opening stock has already been booked at the facility, with Change
 * and Remove for those allowed to correct it.
 */
export function BookedOpeningStock({
    facilityId,
    booked,
    canCorrect,
    open,
    storeId,
}: {
    facilityId: number;
    booked: BookedLine[];
    canCorrect: boolean;
    open: boolean;
    storeId: string;
}) {
    const [search, setSearch] = useState('');
    const [changing, setChanging] = useState<BookedLine | null>(null);
    const [removing, setRemoving] = useState<BookedLine | null>(null);

    const rows = useMemo(() => {
        const q = search.trim().toLowerCase();

        return booked.filter(
            (b) =>
                (storeId === '' || String(b.store_id) === storeId) &&
                (q === '' ||
                    [b.item, b.item_code, b.batch_number, b.number]
                        .filter(Boolean)
                        .some((v) => String(v).toLowerCase().includes(q))),
        );
    }, [booked, search, storeId]);

    return (
        <section className="bg-card overflow-hidden rounded-xl border">
            <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 className="font-semibold">Booked opening stock</h2>
                    <p className="text-muted-foreground text-sm">
                        {canCorrect
                            ? open
                                ? 'Change or remove a line booked by mistake. A batch that has been used, moved or reserved can no longer be changed here.'
                                : 'Opening stock is closed for this facility. Re-open it in the facility settings to change or remove a line.'
                            : 'What has been booked so far. Ask a Super Admin to correct a mistake.'}
                    </p>
                </div>
                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search product, batch or number"
                    className="h-9 sm:w-64"
                    aria-label="Search booked opening stock"
                />
            </div>

            {rows.length === 0 ? (
                <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                    {booked.length === 0
                        ? 'Nothing booked yet.'
                        : 'Nothing booked matches.'}
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground bg-muted/40 text-left text-xs uppercase">
                                <tr className="border-b">
                                    <th className="px-4 py-2.5 font-medium">
                                        Product
                                    </th>
                                    <th className="px-4 py-2.5 font-medium">
                                        Batch
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-medium">
                                        Quantity
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-medium">
                                        Rate
                                    </th>
                                    <th className="px-4 py-2.5 font-medium">
                                        Booked
                                    </th>
                                    <th className="w-px px-4 py-2.5">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((b) => (
                                    <tr
                                        key={b.lot_id}
                                        className={
                                            b.locked === 'Removed'
                                                ? 'text-muted-foreground'
                                                : undefined
                                        }
                                    >
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {b.item}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {b.item_code} · {b.store}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="font-mono text-xs">
                                                {b.batch_number}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                Mfg {day(b.manufactured_at)} ·
                                                Exp {day(b.expiry_at)}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            <span
                                                className={
                                                    b.locked === 'Removed'
                                                        ? 'line-through'
                                                        : 'font-medium'
                                                }
                                            >
                                                {Number(
                                                    b.locked === 'Removed'
                                                        ? b.booked_quantity
                                                        : b.quantity,
                                                )}{' '}
                                                {b.uom}
                                            </span>
                                            {b.corrected &&
                                                b.locked !== 'Removed' && (
                                                    <div className="text-muted-foreground text-xs">
                                                        booked{' '}
                                                        {Number(
                                                            b.booked_quantity,
                                                        )}
                                                    </div>
                                                )}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {b.unit_cost !== null
                                                ? `₹${Number(b.unit_cost)}`
                                                : '—'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="font-mono text-xs">
                                                {b.number}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {day(b.booked_at)}
                                                {b.booked_by
                                                    ? ` · ${b.booked_by}`
                                                    : ''}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-right whitespace-nowrap">
                                            {b.locked ? (
                                                <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                                    <Lock className="size-3" />
                                                    {b.locked}
                                                </span>
                                            ) : (
                                                canCorrect &&
                                                open && (
                                                    <div className="flex justify-end gap-2">
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setChanging(b)
                                                            }
                                                        >
                                                            <Pencil className="size-3.5" />
                                                            Change
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-red-700 hover:text-red-800 dark:text-red-300"
                                                            onClick={() =>
                                                                setRemoving(b)
                                                            }
                                                        >
                                                            <Trash2 className="size-3.5" />
                                                            Remove
                                                        </Button>
                                                    </div>
                                                )
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    <ul className="divide-y md:hidden">
                        {rows.map((b) => (
                            <li key={b.lot_id} className="space-y-2 p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="font-medium">
                                            {b.item}
                                        </div>
                                        <div className="text-muted-foreground text-xs">
                                            {b.store} · batch{' '}
                                            <span className="font-mono">
                                                {b.batch_number}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="text-right font-medium tabular-nums">
                                        {Number(b.quantity)} {b.uom}
                                    </div>
                                </div>
                                {b.locked ? (
                                    <p className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                                        <Lock className="size-3" />
                                        {b.locked}
                                    </p>
                                ) : (
                                    canCorrect &&
                                    open && (
                                        <div className="grid grid-cols-2 gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setChanging(b)}
                                            >
                                                Change
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="text-red-700 dark:text-red-300"
                                                onClick={() => setRemoving(b)}
                                            >
                                                Remove
                                            </Button>
                                        </div>
                                    )
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {changing && (
                <ChangeDialog
                    facilityId={facilityId}
                    line={changing}
                    onClose={() => setChanging(null)}
                />
            )}
            {removing && (
                <RemoveDialog
                    facilityId={facilityId}
                    line={removing}
                    onClose={() => setRemoving(null)}
                />
            )}
        </section>
    );
}
