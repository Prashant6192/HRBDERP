import { useForm } from '@inertiajs/react';
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
import { ARTWORK_KIND_LABEL } from '@/lib/contract';
import type { SelectOption } from '@/types';

const NONE = '__none__';

/**
 * Record one artwork version: a client's (approved by the client) or the
 * company's own (approved in-house). The file is what the packing line
 * opens from the batch, so an image or a PDF of the pack goes with it.
 */
export function ArtworkDialog({
    action,
    products,
    kinds,
    party = 'client',
}: {
    /** Where the form posts. */
    action: string;
    /** Products to file it against; omit when the product is fixed. */
    products?: SelectOption[];
    kinds: string[];
    /** Whose approval the version waits on. */
    party?: 'client' | 'own';
}) {
    const [open, setOpen] = useState(false);
    const form = useForm<{
        product_id: string;
        kind: string;
        title: string;
        version: string;
        status: string;
        approved_at: string;
        approved_by_name: string;
        document: File | null;
        notes: string;
    }>({
        product_id: '',
        kind: 'label',
        title: '',
        version: 'v1',
        status: 'pending',
        approved_at: '',
        approved_by_name: '',
        document: null,
        notes: '',
    });

    const who = party === 'client' ? 'the client' : 'management';

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <Plus className="size-4" />
                    Add artwork
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(action, {
                            forceFormData: true,
                            preserveScroll: true,
                            onSuccess: () => {
                                setOpen(false);
                                form.reset();
                            },
                        });
                    }}
                    className="space-y-4"
                >
                    <DialogHeader>
                        <DialogTitle>Record artwork</DialogTitle>
                        <DialogDescription>
                            A label, tube, bottle or carton artwork version,
                            with the picture or PDF the packing line will match
                            the pack against. Approving a version supersedes the
                            earlier approved one of the same kind.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {products && (
                            <Field
                                label="Product"
                                htmlFor="aw-product"
                                error={form.errors.product_id}
                            >
                                <Select
                                    value={form.data.product_id || NONE}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'product_id',
                                            v === NONE ? '' : v,
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        id="aw-product"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>
                                            All products
                                        </SelectItem>
                                        {products.map((p) => (
                                            <SelectItem
                                                key={p.value}
                                                value={String(p.value)}
                                            >
                                                {p.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                        <Field
                            label="Kind"
                            htmlFor="aw-kind"
                            error={form.errors.kind}
                        >
                            <Select
                                value={form.data.kind}
                                onValueChange={(v) => form.setData('kind', v)}
                            >
                                <SelectTrigger id="aw-kind" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {kinds.map((k) => (
                                        <SelectItem key={k} value={k}>
                                            {ARTWORK_KIND_LABEL[k] ?? k}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Title"
                            htmlFor="aw-title"
                            required
                            error={form.errors.title}
                        >
                            <Input
                                id="aw-title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                placeholder="Front label"
                            />
                        </Field>
                        <Field
                            label="Version"
                            htmlFor="aw-version"
                            required
                            error={form.errors.version}
                        >
                            <Input
                                id="aw-version"
                                value={form.data.version}
                                onChange={(e) =>
                                    form.setData('version', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Status"
                            htmlFor="aw-status"
                            error={form.errors.status}
                        >
                            <Select
                                value={form.data.status}
                                onValueChange={(v) => form.setData('status', v)}
                            >
                                <SelectTrigger
                                    id="aw-status"
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="pending">
                                        Awaiting approval by {who}
                                    </SelectItem>
                                    <SelectItem value="approved">
                                        Approved by {who}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>
                        {form.data.status === 'approved' && (
                            <>
                                <Field
                                    label="Approved on"
                                    htmlFor="aw-approved-at"
                                    required
                                    error={form.errors.approved_at}
                                >
                                    <Input
                                        id="aw-approved-at"
                                        type="date"
                                        value={form.data.approved_at}
                                        onChange={(e) =>
                                            form.setData(
                                                'approved_at',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label={
                                        party === 'client'
                                            ? 'Approved by (client side)'
                                            : 'Approved by'
                                    }
                                    htmlFor="aw-approved-by"
                                    error={form.errors.approved_by_name}
                                >
                                    <Input
                                        id="aw-approved-by"
                                        value={form.data.approved_by_name}
                                        onChange={(e) =>
                                            form.setData(
                                                'approved_by_name',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </>
                        )}
                        <Field
                            label="Artwork file"
                            htmlFor="aw-document"
                            error={form.errors.document}
                            hint="A picture (JPEG, PNG, WebP) or a PDF of the pack, up to 20 MB."
                            className="sm:col-span-2"
                        >
                            <Input
                                id="aw-document"
                                type="file"
                                accept="application/pdf,image/jpeg,image/png,image/webp"
                                onChange={(e) =>
                                    form.setData(
                                        'document',
                                        e.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Notes"
                            htmlFor="aw-notes"
                            error={form.errors.notes}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="aw-notes"
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Save artwork
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
