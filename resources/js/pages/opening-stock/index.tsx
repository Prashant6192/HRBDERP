import { Head, router, useForm } from '@inertiajs/react';
import { Download, FileUp, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { Field } from '@/components/form-field';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
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
import { dashboard } from '@/routes';
import { index, parse, store, template } from '@/routes/opening-stock';
import type { SelectOption } from '@/types';

type ItemOption = SelectOption & {
    type: string;
    uom_id: number;
    uom: string | null;
    standard_cost: string | null;
};

type Kind = {
    key: 'raw_material' | 'packaging' | 'finished_goods';
    label: string;
    stores: SelectOption[];
    items: ItemOption[];
};

type Line = {
    item_id: string;
    quantity: string;
    uom_id: string;
    batch_number: string;
    manufactured_at: string;
    expiry_at: string;
    unit_cost: string;
    remarks: string;
};

type Section = { kind: Kind['key']; warehouse_id: string; lines: Line[] };

const EMPTY: Line = {
    item_id: '',
    quantity: '',
    uom_id: '',
    batch_number: '',
    manufactured_at: '',
    expiry_at: '',
    unit_cost: '',
    remarks: '',
};

function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export default function OldStock({
    facilities,
    facility,
    kinds,
    uoms,
    booked,
    today,
}: {
    facilities: (SelectOption & { opening_stock_enabled: boolean })[];
    facility: {
        id: number;
        code: string;
        name: string;
        opening_stock_enabled: boolean;
    } | null;
    kinds: Kind[];
    uoms: (SelectOption & { dimension: string })[];
    booked: {
        id: number;
        number: string;
        store: string | null;
        lines: number;
        at: string | null;
    }[];
    today: string;
}) {
    const form = useForm<{
        facility_id: string;
        as_of: string;
        remarks: string;
        sections: Section[];
    }>({
        facility_id: facility ? String(facility.id) : '',
        as_of: today,
        remarks: '',
        sections: kinds.map((k) => ({
            kind: k.key,
            warehouse_id:
                k.stores.length === 1 ? String(k.stores[0].value) : '',
            lines: [],
        })),
    });
    const [problems, setProblems] = useState<Record<string, string[]>>({});
    const [uploading, setUploading] = useState<string | null>(null);
    const inputs = useRef<Record<string, HTMLInputElement | null>>({});
    const errors = form.errors as Record<string, string | undefined>;

    const section = (key: Kind['key']) =>
        form.data.sections.findIndex((s) => s.kind === key);
    const setSection = (i: number, patch: Partial<Section>) =>
        form.setData(
            'sections',
            form.data.sections.map((s, j) =>
                j === i ? { ...s, ...patch } : s,
            ),
        );
    const setLine = (i: number, l: number, patch: Partial<Line>) =>
        setSection(i, {
            lines: form.data.sections[i].lines.map((line, j) =>
                j === l ? { ...line, ...patch } : line,
            ),
        });

    const chooseItem = (kind: Kind, i: number, l: number, value: string) => {
        const item = kind.items.find((it) => String(it.value) === value);
        setLine(i, l, {
            item_id: value,
            uom_id: item ? String(item.uom_id) : '',
            unit_cost:
                form.data.sections[i].lines[l].unit_cost ||
                (item?.standard_cost ?? ''),
        });
    };

    const upload = async (kind: Kind, i: number, file: File | undefined) => {
        if (!file) return;
        setUploading(kind.key);
        setProblems((p) => ({ ...p, [kind.key]: [] }));
        try {
            const body = new FormData();
            body.append('kind', kind.key);
            body.append('sheet', file);
            const response = await fetch(parse().url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body,
            });
            const json = (await response.json()) as {
                lines?: (Line & {
                    item_id: number;
                    uom_id: number | null;
                    item_label: string;
                    row: number;
                })[];
                problems?: string[];
                message?: string;
                errors?: Record<string, string[]>;
            };
            if (!response.ok) {
                setProblems((p) => ({
                    ...p,
                    [kind.key]: [
                        json.message ??
                            Object.values(json.errors ?? {})[0]?.[0] ??
                            'The sheet could not be read.',
                    ],
                }));
                return;
            }
            const lines: Line[] = (json.lines ?? []).map((l) => ({
                item_id: String(l.item_id),
                quantity: String(l.quantity ?? ''),
                uom_id: l.uom_id ? String(l.uom_id) : '',
                batch_number: l.batch_number ?? '',
                manufactured_at: l.manufactured_at ?? '',
                expiry_at: l.expiry_at ?? '',
                unit_cost: l.unit_cost ?? '',
                remarks: l.remarks ?? '',
            }));
            setSection(i, {
                lines: [
                    ...form.data.sections[i].lines.filter(
                        (l) => l.item_id !== '' || l.quantity !== '',
                    ),
                    ...lines,
                ],
            });
            setProblems((p) => ({
                ...p,
                [kind.key]: [
                    ...(json.problems ?? []),
                    ...(lines.length > 0
                        ? [
                              `${lines.length} line${lines.length === 1 ? '' : 's'} read from the sheet and added below. Check them, then post.`,
                          ]
                        : []),
                ],
            }));
        } catch {
            setProblems((p) => ({
                ...p,
                [kind.key]: ['Could not reach the server. Try again.'],
            }));
        } finally {
            setUploading(null);
        }
    };

    const total = form.data.sections.reduce((n, s) => n + s.lines.length, 0);
    const enabled = facility?.opening_stock_enabled ?? false;

    return (
        <>
            <Head title="Old stock entry" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Old stock entry"
                    description="The stock already on the shelf when the ERP goes live. Each line becomes a batch in the right store, QC marked passed, posted to the ledger as an opening balance. Enter it by hand or fill in the sheet and upload it. Nothing here can be edited afterwards, only adjusted through the ledger."
                    actions={
                        facilities.length > 1 ? (
                            <Select
                                value={form.data.facility_id}
                                onValueChange={(v) =>
                                    router.get(
                                        index().url,
                                        { facility: v },
                                        { preserveState: false },
                                    )
                                }
                            >
                                <SelectTrigger className="min-w-56">
                                    <SelectValue placeholder="Facility" />
                                </SelectTrigger>
                                <SelectContent>
                                    {facilities.map((f) => (
                                        <SelectItem
                                            key={f.value}
                                            value={String(f.value)}
                                        >
                                            {f.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        ) : undefined
                    }
                />

                {!facility && (
                    <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                        No active facility yet. Create the factory under
                        Facilities first.
                    </div>
                )}
                {facility && !enabled && (
                    <div className="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm">
                        Opening stock entry is closed for {facility.name}.
                        Re-open it under the facility&rsquo;s Settings tab, book
                        the old stock, then close it again.
                    </div>
                )}

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(store().url, { preserveScroll: true });
                    }}
                    className="space-y-6"
                >
                    <section className="bg-card grid gap-4 rounded-xl border p-5 sm:grid-cols-[auto_1fr]">
                        <Field
                            label="Counted on"
                            htmlFor="as_of"
                            required
                            error={errors.as_of}
                            hint="The date the shelves were counted."
                        >
                            <Input
                                id="as_of"
                                type="date"
                                value={form.data.as_of}
                                max={today}
                                onChange={(e) =>
                                    form.setData('as_of', e.target.value)
                                }
                                className="sm:w-48"
                            />
                        </Field>
                        <Field
                            label="Remarks"
                            htmlFor="remarks"
                            error={errors.remarks}
                        >
                            <Input
                                id="remarks"
                                value={form.data.remarks}
                                onChange={(e) =>
                                    form.setData('remarks', e.target.value)
                                }
                                placeholder={`Physical count at ${facility?.name ?? 'the factory'} before go-live`}
                            />
                        </Field>
                    </section>

                    <InputError message={errors.sections} />

                    {kinds.map((kind) => {
                        const i = section(kind.key);
                        const s = form.data.sections[i];
                        const notes = problems[kind.key] ?? [];

                        return (
                            <section
                                key={kind.key}
                                className="bg-card rounded-xl border"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                                    <div>
                                        <h2 className="font-semibold">
                                            {kind.label}
                                        </h2>
                                        <p className="text-muted-foreground text-xs">
                                            {s.lines.length === 0
                                                ? 'Nothing added yet.'
                                                : `${s.lines.length} line${s.lines.length === 1 ? '' : 's'}`}
                                            {kind.stores.length === 0
                                                ? ` · ${facility?.name ?? 'This facility'} has no ${kind.label.toLowerCase()} store.`
                                                : ''}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {kind.stores.length > 1 && (
                                            <Select
                                                value={s.warehouse_id}
                                                onValueChange={(v) =>
                                                    setSection(i, {
                                                        warehouse_id: v,
                                                    })
                                                }
                                            >
                                                <SelectTrigger className="min-w-48">
                                                    <SelectValue placeholder="Store" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {kind.stores.map((st) => (
                                                        <SelectItem
                                                            key={st.value}
                                                            value={String(
                                                                st.value,
                                                            )}
                                                        >
                                                            {st.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            asChild
                                        >
                                            <a href={template(kind.key).url}>
                                                <Download className="size-4" />
                                                Sheet template
                                            </a>
                                        </Button>
                                        <input
                                            ref={(el) => {
                                                inputs.current[kind.key] = el;
                                            }}
                                            type="file"
                                            accept=".xlsx,.xls,.csv"
                                            className="hidden"
                                            onChange={(e) => {
                                                void upload(
                                                    kind,
                                                    i,
                                                    e.target.files?.[0],
                                                );
                                                e.target.value = '';
                                            }}
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={
                                                uploading === kind.key ||
                                                kind.stores.length === 0
                                            }
                                            onClick={() =>
                                                inputs.current[
                                                    kind.key
                                                ]?.click()
                                            }
                                        >
                                            <FileUp className="size-4" />
                                            {uploading === kind.key
                                                ? 'Reading…'
                                                : 'Upload filled sheet'}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={kind.stores.length === 0}
                                            onClick={() =>
                                                setSection(i, {
                                                    lines: [
                                                        ...s.lines,
                                                        { ...EMPTY },
                                                    ],
                                                })
                                            }
                                        >
                                            <Plus className="size-4" />
                                            Add line
                                        </Button>
                                    </div>
                                </div>
                                <InputError
                                    message={
                                        errors[`sections.${i}.warehouse_id`]
                                    }
                                    className="px-5 pt-3"
                                />
                                {notes.length > 0 && (
                                    <ul className="space-y-1 px-5 pt-3 text-sm">
                                        {notes.map((n, k) => (
                                            <li
                                                key={k}
                                                className={
                                                    n.startsWith('Row')
                                                        ? 'text-amber-700 dark:text-amber-300'
                                                        : 'text-muted-foreground'
                                                }
                                            >
                                                {n}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {s.lines.length > 0 && (
                                    <div className="overflow-x-auto">
                                        <Table>
                                            <TableHeader>
                                                <TableRow>
                                                    <TableHead className="min-w-60">
                                                        Material
                                                    </TableHead>
                                                    <TableHead className="min-w-36">
                                                        Batch no.
                                                    </TableHead>
                                                    <TableHead className="min-w-28">
                                                        Quantity
                                                    </TableHead>
                                                    <TableHead className="min-w-24">
                                                        Unit
                                                    </TableHead>
                                                    <TableHead className="min-w-36">
                                                        Mfg date
                                                    </TableHead>
                                                    <TableHead className="min-w-36">
                                                        Expiry
                                                    </TableHead>
                                                    <TableHead className="min-w-28">
                                                        Rate ₹
                                                    </TableHead>
                                                    <TableHead className="min-w-40">
                                                        Remarks
                                                    </TableHead>
                                                    <TableHead />
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody>
                                                {s.lines.map((line, l) => {
                                                    const item =
                                                        kind.items.find(
                                                            (it) =>
                                                                String(
                                                                    it.value,
                                                                ) ===
                                                                line.item_id,
                                                        );
                                                    const err = (
                                                        field: string,
                                                    ) =>
                                                        errors[
                                                            `sections.${i}.lines.${l}.${field}`
                                                        ];

                                                    return (
                                                        <TableRow key={l}>
                                                            <TableCell>
                                                                <Select
                                                                    value={
                                                                        line.item_id
                                                                    }
                                                                    onValueChange={(
                                                                        v,
                                                                    ) =>
                                                                        chooseItem(
                                                                            kind,
                                                                            i,
                                                                            l,
                                                                            v,
                                                                        )
                                                                    }
                                                                >
                                                                    <SelectTrigger className="w-full">
                                                                        <SelectValue placeholder="Choose" />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        {kind.items.map(
                                                                            (
                                                                                it,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        it.value
                                                                                    }
                                                                                    value={String(
                                                                                        it.value,
                                                                                    )}
                                                                                >
                                                                                    {
                                                                                        it.label
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectContent>
                                                                </Select>
                                                                <InputError
                                                                    message={err(
                                                                        'item_id',
                                                                    )}
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    value={
                                                                        line.batch_number
                                                                    }
                                                                    placeholder="Blank → generated"
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                batch_number:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                <InputError
                                                                    message={err(
                                                                        'batch_number',
                                                                    )}
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    type="number"
                                                                    step="any"
                                                                    min="0"
                                                                    value={
                                                                        line.quantity
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                quantity:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                                <InputError
                                                                    message={err(
                                                                        'quantity',
                                                                    )}
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Select
                                                                    value={
                                                                        line.uom_id
                                                                    }
                                                                    onValueChange={(
                                                                        v,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                uom_id: v,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <SelectTrigger className="w-full">
                                                                        <SelectValue
                                                                            placeholder={
                                                                                item?.uom ??
                                                                                'Unit'
                                                                            }
                                                                        />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        {uoms.map(
                                                                            (
                                                                                u,
                                                                            ) => (
                                                                                <SelectItem
                                                                                    key={
                                                                                        u.value
                                                                                    }
                                                                                    value={String(
                                                                                        u.value,
                                                                                    )}
                                                                                >
                                                                                    {
                                                                                        u.label
                                                                                    }
                                                                                </SelectItem>
                                                                            ),
                                                                        )}
                                                                    </SelectContent>
                                                                </Select>
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    type="date"
                                                                    value={
                                                                        line.manufactured_at
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                manufactured_at:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    type="date"
                                                                    value={
                                                                        line.expiry_at
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                expiry_at:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    type="number"
                                                                    step="any"
                                                                    min="0"
                                                                    value={
                                                                        line.unit_cost
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                unit_cost:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Input
                                                                    value={
                                                                        line.remarks
                                                                    }
                                                                    onChange={(
                                                                        e,
                                                                    ) =>
                                                                        setLine(
                                                                            i,
                                                                            l,
                                                                            {
                                                                                remarks:
                                                                                    e
                                                                                        .target
                                                                                        .value,
                                                                            },
                                                                        )
                                                                    }
                                                                />
                                                            </TableCell>
                                                            <TableCell>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() =>
                                                                        setSection(
                                                                            i,
                                                                            {
                                                                                lines: s.lines.filter(
                                                                                    (
                                                                                        _,
                                                                                        j,
                                                                                    ) =>
                                                                                        j !==
                                                                                        l,
                                                                                ),
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </TableCell>
                                                        </TableRow>
                                                    );
                                                })}
                                            </TableBody>
                                        </Table>
                                    </div>
                                )}
                            </section>
                        );
                    })}

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="submit"
                            disabled={
                                form.processing || !enabled || total === 0
                            }
                        >
                            Post{' '}
                            {total > 0
                                ? `${total} line${total === 1 ? '' : 's'}`
                                : 'old stock'}
                        </Button>
                        <p className="text-muted-foreground text-sm">
                            Posts one opening balance per store. QC is marked
                            passed on every batch; the stock is usable at once.
                        </p>
                    </div>
                </form>

                {booked.length > 0 && (
                    <section className="bg-card rounded-xl border">
                        <h2 className="border-b px-5 py-4 font-semibold">
                            Already booked at {facility?.name}
                        </h2>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Posting</TableHead>
                                    <TableHead>Store</TableHead>
                                    <TableHead className="text-right">
                                        Lines
                                    </TableHead>
                                    <TableHead>As of</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {booked.map((b) => (
                                    <TableRow key={b.id}>
                                        <TableCell className="font-mono">
                                            {b.number}
                                        </TableCell>
                                        <TableCell>{b.store ?? '—'}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {b.lines}
                                        </TableCell>
                                        <TableCell>{b.at ?? '—'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </section>
                )}
            </div>
        </>
    );
}

OldStock.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Old stock entry', href: index() },
    ],
};
