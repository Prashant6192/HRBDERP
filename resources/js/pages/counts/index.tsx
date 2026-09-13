import { Head, Link, useForm } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import { index, show, store } from '@/routes/counts';
import type { SelectOption } from '@/types';

type CountRow = {
    id: number;
    number: string;
    store: string | null;
    store_code: string | null;
    status: 'counting' | 'submitted' | 'approved' | 'cancelled';
    status_label: string;
    lines: number;
    started_by: string | null;
    started_at: string | null;
    approved_by: string | null;
    accuracy: string | null;
};

const STATUS = {
    counting: 'warning',
    submitted: 'info',
    approved: 'success',
    cancelled: 'muted',
} as const;

export default function CountsIndex({
    counts,
    stores,
    preselect,
    can,
}: {
    counts: CountRow[];
    stores: SelectOption[];
    preselect: number | null;
    can: { create: boolean };
}) {
    const [open, setOpen] = useState(Boolean(preselect));
    const form = useForm({
        warehouse_id: preselect ? String(preselect) : '',
        notes: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(store().url);
    };

    return (
        <>
            <Head title="Stock counts" />
            <div className="flex flex-1 flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Stock counts"
                    description="A count freezes what the system says a store holds, records what is on the shelf, and — once someone other than the counter approves it — posts every difference as an adjustment. Inventory accuracy is read from the lines."
                    actions={
                        can.create ? (
                            <Button
                                size="sm"
                                onClick={() => setOpen((o) => !o)}
                            >
                                <ClipboardList className="size-4" />
                                Start a count
                            </Button>
                        ) : undefined
                    }
                />

                {open && can.create && (
                    <form
                        onSubmit={submit}
                        className="bg-card grid gap-4 rounded-2xl border p-5 sm:grid-cols-[1fr_2fr_auto] sm:items-end"
                    >
                        <div>
                            <Label>Store</Label>
                            <Select
                                value={form.data.warehouse_id}
                                onValueChange={(v) =>
                                    form.setData('warehouse_id', v)
                                }
                            >
                                <SelectTrigger className="mt-1">
                                    <SelectValue placeholder="Choose a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    {stores.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={String(s.value)}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.warehouse_id} />
                        </div>
                        <div>
                            <Label htmlFor="notes">Notes</Label>
                            <Input
                                id="notes"
                                className="mt-1"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                                placeholder="e.g. month-end count, rack A only"
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || !form.data.warehouse_id
                            }
                        >
                            Start
                        </Button>
                    </form>
                )}

                <div className="bg-card overflow-x-auto rounded-2xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Count</TableHead>
                                <TableHead>Store</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Lines
                                </TableHead>
                                <TableHead>Started</TableHead>
                                <TableHead>Approved by</TableHead>
                                <TableHead className="text-right">
                                    Accuracy
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {counts.length === 0 ? (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="text-muted-foreground py-12 text-center"
                                    >
                                        No count yet.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                counts.map((c) => (
                                    <TableRow key={c.id}>
                                        <TableCell>
                                            <Link
                                                href={show(c.id)}
                                                className="font-mono font-medium underline-offset-4 hover:underline"
                                            >
                                                {c.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            {c.store}
                                            <div className="text-muted-foreground text-xs">
                                                {c.store_code}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                variant={STATUS[c.status]}
                                            >
                                                {c.status_label}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {c.lines}
                                        </TableCell>
                                        <TableCell>
                                            {c.started_at}
                                            <div className="text-muted-foreground text-xs">
                                                {c.started_by}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {c.approved_by ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {c.accuracy === null
                                                ? '—'
                                                : `${c.accuracy}%`}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

CountsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Stock counts', href: index() },
    ],
};
