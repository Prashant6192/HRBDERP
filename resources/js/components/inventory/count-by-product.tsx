import { router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    SearchableSelect,
    type SearchableOption,
} from '@/components/ui/searchable-select';
import { qty } from '@/lib/stock';
import { cn } from '@/lib/utils';
import { keepRest, products as productsRoute } from '@/routes/counts';

export type CountProduct = {
    item_id: number;
    code: string | null;
    name: string | null;
    unit: string | null;
    batches: number;
    system: string;
    counted: string | null;
    variance: string | null;
};

/**
 * A count entered as a register has it: one total per product, no
 * batches. Rows left blank are not touched; "Keep the rest" says the
 * products not counted stay as the system has them.
 */
export function CountByProduct({
    countId,
    products,
    addable,
    editable,
}: {
    countId: number;
    products: CountProduct[];
    addable: SearchableOption[];
    editable: boolean;
}) {
    const [typed, setTyped] = useState<Record<number, string>>({});
    const [extra, setExtra] = useState<{ item_id: string; counted: string }[]>(
        [],
    );
    const [saving, setSaving] = useState(false);

    const rows = [
        ...Object.entries(typed)
            .filter(([, v]) => v.trim() !== '')
            .map(([id, v]) => ({ item_id: Number(id), counted: Number(v) })),
        ...extra
            .filter((e) => e.item_id !== '' && e.counted.trim() !== '')
            .map((e) => ({
                item_id: Number(e.item_id),
                counted: Number(e.counted),
            })),
    ];
    const uncounted = products.filter((p) => p.counted === null).length;

    const save = () => {
        setSaving(true);
        router.post(
            productsRoute(countId).url,
            { products: rows },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTyped({});
                    setExtra([]);
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <section className="bg-card rounded-2xl border">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                <div>
                    <h2 className="text-sm font-semibold">Count by product</h2>
                    <p className="text-muted-foreground text-xs">
                        One total per product, as in the register. No batch
                        numbers. Nothing changes in stock until the count is
                        approved.
                    </p>
                </div>
                {editable && (
                    <div className="flex flex-wrap gap-2">
                        {uncounted > 0 && (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="outline" size="sm">
                                        Keep the rest as the system has it
                                    </Button>
                                }
                                title={`Keep ${uncounted} product(s) not counted?`}
                                description="Products with no count entered stay exactly as the system has them. Use this for products that were not on the register."
                                confirmLabel="Keep them"
                                action={() =>
                                    router.post(
                                        keepRest(countId).url,
                                        undefined,
                                        { preserveScroll: true },
                                    )
                                }
                            />
                        )}
                        <Button
                            size="sm"
                            disabled={rows.length === 0 || saving}
                            onClick={save}
                        >
                            Save {rows.length > 0 ? rows.length : ''} count(s)
                        </Button>
                    </div>
                )}
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left text-xs">
                            <th className="px-4 py-2 font-medium">Product</th>
                            <th className="px-4 py-2 text-right font-medium">
                                System
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Counted
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Difference
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {products.map((p) => {
                            const now = typed[p.item_id];
                            const shown =
                                now !== undefined && now.trim() !== ''
                                    ? Number(now) - Number(p.system)
                                    : p.variance === null
                                      ? null
                                      : Number(p.variance);

                            return (
                                <tr
                                    key={p.item_id}
                                    className="border-b last:border-0"
                                >
                                    <td className="px-4 py-2">
                                        <div className="font-medium">
                                            {p.name}
                                        </div>
                                        <div className="text-muted-foreground font-mono text-xs">
                                            {p.code}
                                        </div>
                                    </td>
                                    <td className="px-4 py-2 text-right tabular-nums">
                                        {qty(p.system)} {p.unit}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        {editable ? (
                                            <Input
                                                type="number"
                                                min={0}
                                                inputMode="numeric"
                                                className="ml-auto w-28 text-right"
                                                aria-label={`Counted ${p.name ?? ''}`}
                                                placeholder={
                                                    p.counted === null
                                                        ? 'not counted'
                                                        : qty(p.counted)
                                                }
                                                value={now ?? ''}
                                                onChange={(e) =>
                                                    setTyped({
                                                        ...typed,
                                                        [p.item_id]:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        ) : (
                                            <span className="tabular-nums">
                                                {p.counted === null
                                                    ? '—'
                                                    : qty(p.counted)}
                                            </span>
                                        )}
                                    </td>
                                    <td
                                        className={cn(
                                            'px-4 py-2 text-right font-medium tabular-nums',
                                            shown === null || shown === 0
                                                ? 'text-muted-foreground'
                                                : shown > 0
                                                  ? 'text-emerald-700 dark:text-emerald-300'
                                                  : 'text-red-700 dark:text-red-300',
                                        )}
                                    >
                                        {shown === null
                                            ? '—'
                                            : shown === 0
                                              ? 'matches'
                                              : `${shown > 0 ? '+' : '−'}${qty(String(Math.abs(shown)))}`}
                                    </td>
                                </tr>
                            );
                        })}
                        {editable &&
                            extra.map((e, i) => (
                                <tr key={`extra-${i}`} className="border-b">
                                    <td className="px-4 py-2">
                                        <SearchableSelect
                                            value={e.item_id}
                                            onValueChange={(v) =>
                                                setExtra(
                                                    extra.map((x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  item_id: v,
                                                              }
                                                            : x,
                                                    ),
                                                )
                                            }
                                            options={addable}
                                            placeholder="Which product was found?"
                                        />
                                    </td>
                                    <td className="text-muted-foreground px-4 py-2 text-right">
                                        0
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <Input
                                            type="number"
                                            min={0}
                                            inputMode="numeric"
                                            className="ml-auto w-28 text-right"
                                            aria-label="Counted"
                                            value={e.counted}
                                            onChange={(ev) =>
                                                setExtra(
                                                    extra.map((x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  counted:
                                                                      ev.target
                                                                          .value,
                                                              }
                                                            : x,
                                                    ),
                                                )
                                            }
                                        />
                                    </td>
                                    <td />
                                </tr>
                            ))}
                    </tbody>
                </table>
            </div>
            {editable && addable.length > 0 && (
                <div className="border-t px-4 py-3">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setExtra([...extra, { item_id: '', counted: '' }])
                        }
                    >
                        <Plus className="size-4" />
                        Add a product the system does not have here
                    </Button>
                </div>
            )}
        </section>
    );
}
