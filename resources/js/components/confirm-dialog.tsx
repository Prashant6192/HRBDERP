import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
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

type ConfirmDialogProps = {
    trigger: ReactNode;
    title: string;
    description: string;
    confirmLabel?: string;
    /** Where to send the request when confirmed. */
    action: () => void;
    destructive?: boolean;
};

/**
 * A deliberate pause before something irreversible.
 *
 * Used for deletions and deactivations, where the cost of a mis-click is a
 * conversation with whoever noticed.
 */
export function ConfirmDialog({
    trigger,
    title,
    description,
    confirmLabel = 'Confirm',
    action,
    destructive = false,
}: ConfirmDialogProps) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2 sm:gap-2">
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={() => {
                            setOpen(false);
                            action();
                        }}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Convenience wrapper for the common "delete this record" case.
 */
export function DeleteDialog({
    url,
    label,
    trigger,
}: {
    url: string;
    label: string;
    trigger: ReactNode;
}) {
    return (
        <ConfirmDialog
            trigger={trigger}
            title={`Remove ${label}?`}
            description={`${label} will be removed from the active list. Its history is retained and nothing that already refers to it is affected.`}
            confirmLabel="Remove"
            destructive
            action={() => router.delete(url, { preserveScroll: true })}
        />
    );
}
