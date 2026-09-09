import { Link, router, usePage } from '@inertiajs/react';
import { Lock, LockOpen, Timer } from 'lucide-react';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { lock, unlock } from '@/routes/formulas';
import type { SharedData } from '@/types';

/**
 * The state of the second factor, shown wherever formulas are handled.
 *
 * Purely informational: the server decides on every request whether the
 * unlock still stands. This only tells the person how long they have.
 */
export function FormulaLockChip() {
    const { formulaAccess } = usePage<SharedData>().props;

    if (!formulaAccess) {
        return null;
    }

    if (!formulaAccess.unlocked) {
        return (
            <div className="flex items-center gap-2">
                <StatusBadge variant="muted">
                    <Lock className="size-3" />
                    Locked
                </StatusBadge>
                <Button asChild size="sm" variant="outline">
                    <Link href={unlock()}>Unlock</Link>
                </Button>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2">
            <StatusBadge variant="success">
                <LockOpen className="size-3" />
                Unlocked
            </StatusBadge>
            <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                <Timer className="size-3" />
                {formulaAccess.minutes_remaining} min left
            </span>
            <Button
                size="sm"
                variant="ghost"
                onClick={() => router.post(lock().url)}
            >
                Lock now
            </Button>
        </div>
    );
}
