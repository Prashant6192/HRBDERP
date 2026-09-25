import { useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import InputError from '@/components/input-error';
import { StatusBadge } from '@/components/status-badge';
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
import { TONE_VARIANT } from '@/lib/dispatch';
import { courierName, describeParcel, type Parcel } from '@/lib/online-orders';
import { lookup } from '@/routes/online-orders';
import { cancel as cancelParcel } from '@/routes/online-orders/parcels';

const REASONS = [
    'Cancelled by the customer',
    'Cancelled by the marketplace',
    'Out of stock',
    'Duplicate label',
];

/**
 * Cancel an order from anywhere: scan or type the AWB or order number,
 * see the parcel, give the reason. Or start from a parcel already chosen.
 */
export function CancelOrderDialog({
    open,
    onOpenChange,
    parcel: preset,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    parcel?: Parcel | null;
}) {
    const [code, setCode] = useState('');
    const [finding, setFinding] = useState(false);
    const [found, setFound] = useState<{
        parcel: Parcel;
        canCancel: boolean;
    } | null>(preset ? { parcel: preset, canCancel: true } : null);
    const [problem, setProblem] = useState<string | null>(null);
    const form = useForm({ reason: '' });

    useEffect(() => {
        if (open) {
            setFound(preset ? { parcel: preset, canCancel: true } : null);
            setProblem(null);
            setCode('');
            form.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, preset?.id]);

    const find = async (e: FormEvent) => {
        e.preventDefault();

        if (code.trim() === '') {
            return;
        }

        setFinding(true);
        setProblem(null);
        setFound(null);

        try {
            const response = await fetch(
                lookup({ query: { code: code.trim() } }).url,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            );
            const data = (await response.json()) as {
                shipment?: Parcel;
                can_cancel?: boolean;
                message?: string;
            };

            if (!response.ok || !data.shipment) {
                setProblem(data.message ?? 'No parcel has that code.');

                return;
            }

            setFound({
                parcel: data.shipment,
                canCancel: data.can_cancel ?? false,
            });
        } catch {
            setProblem('Could not reach the server. Try again.');
        } finally {
            setFinding(false);
        }
    };

    const p = found?.parcel;
    const why = !p
        ? null
        : p.status === 'cancelled'
          ? `Already cancelled${p.cancel_reason ? `: ${p.cancel_reason}` : ''}.`
          : p.status === 'handed_over'
            ? 'It is with the courier. When it comes back, receive it as a return.'
            : p.status === 'returned'
              ? 'It already came back as a return.'
              : !found?.canCancel
                ? 'You cannot cancel this parcel. Ask the dispatch office.'
                : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Cancel an order</DialogTitle>
                    <DialogDescription>
                        Scan the label or type the AWB or order number. Held
                        stock is let go; a packed parcel&rsquo;s stock goes back
                        on the shelf.
                    </DialogDescription>
                </DialogHeader>

                {!preset && (
                    <form onSubmit={find} className="flex gap-2">
                        <Input
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            placeholder="AWB or order number"
                            autoFocus
                            aria-label="AWB or order number"
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={finding || code.trim() === ''}
                        >
                            <Search className="size-4" />
                            Find
                        </Button>
                    </form>
                )}

                {problem && (
                    <p className="text-sm text-red-700 dark:text-red-300">
                        {problem}
                    </p>
                )}

                {p && (
                    <div className="bg-muted/40 space-y-1 rounded-lg border p-3 text-sm">
                        <div className="flex items-center justify-between gap-2">
                            <span className="font-mono font-medium">
                                {p.awb ?? p.order_number}
                            </span>
                            <StatusBadge variant={TONE_VARIANT[p.status_tone]}>
                                {p.status_label}
                            </StatusBadge>
                        </div>
                        <div className="text-muted-foreground">
                            {p.brand} · {p.marketplace} ·{' '}
                            {courierName(p.courier)} · order{' '}
                            {p.order_number ?? '—'}
                        </div>
                        <div>{describeParcel(p)}</div>
                        {p.status === 'packed' && !why && (
                            <p className="font-medium text-amber-700 dark:text-amber-300">
                                Already packed: take the goods out of the box
                                and put them back on the shelf.
                            </p>
                        )}
                    </div>
                )}

                {why && (
                    <p className="text-sm font-medium text-amber-700 dark:text-amber-300">
                        {why}
                    </p>
                )}

                {p && !why && (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(cancelParcel(p.id).url, {
                                preserveScroll: true,
                                onSuccess: () => onOpenChange(false),
                            });
                        }}
                        className="space-y-3"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="cancel-reason">Reason</Label>
                            <div className="flex flex-wrap gap-2">
                                {REASONS.map((r) => (
                                    <button
                                        key={r}
                                        type="button"
                                        onClick={() =>
                                            form.setData('reason', r)
                                        }
                                        className={
                                            form.data.reason === r
                                                ? 'bg-primary text-primary-foreground border-primary rounded-full border px-3 py-1 text-xs'
                                                : 'hover:bg-muted rounded-full border px-3 py-1 text-xs'
                                        }
                                    >
                                        {r}
                                    </button>
                                ))}
                            </div>
                            <Input
                                id="cancel-reason"
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                                placeholder="Or write the reason"
                                required
                            />
                            <InputError message={form.errors.reason} />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Back
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={
                                    form.processing ||
                                    form.data.reason.trim() === ''
                                }
                            >
                                Cancel this order
                            </Button>
                        </DialogFooter>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
