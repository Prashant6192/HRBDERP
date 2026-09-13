import { useForm } from '@inertiajs/react';
import { useState, type FormEvent, type ReactNode } from 'react';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { reverse } from '@/routes/ledger';

/**
 * Corrections are reversals: the original posting stays, an opposite one
 * is added, and both point at each other.
 */
export function ReverseDialog({
    transactionId,
    number,
    trigger,
}: {
    transactionId: number;
    number: string;
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(reverse(transactionId).url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Reverse {number}?</DialogTitle>
                        <DialogDescription>
                            The posting is never edited or deleted. An opposite
                            posting is added, every line negated, so balances
                            return to where they were; both stay in the ledger,
                            each pointing at the other.
                        </DialogDescription>
                    </DialogHeader>
                    <div>
                        <Label htmlFor={`reason-${transactionId}`}>Why</Label>
                        <Input
                            id={`reason-${transactionId}`}
                            className="mt-1"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="e.g. keyed against the wrong batch"
                            autoFocus
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setOpen(false)}
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
                            Reverse posting
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
