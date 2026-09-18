import type { DispatchTone } from '@/types';

/** A status tone from the server, as a badge variant. */
export const TONE_VARIANT: Record<
    DispatchTone,
    'success' | 'warning' | 'destructive' | 'muted' | 'info'
> = {
    neutral: 'muted',
    info: 'info',
    warning: 'warning',
    success: 'success',
    danger: 'destructive',
};

/** Rupees to the paisa, Indian grouping. */
export function rupees(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);

    if (!Number.isFinite(n)) {
        return '—';
    }

    return `₹${n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function when(value: string | null | undefined): string {
    return value
        ? new Date(value).toLocaleString('en-IN', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}

export function day(value: string | null | undefined): string {
    return value
        ? new Date(value).toLocaleDateString('en-IN', { dateStyle: 'medium' })
        : '—';
}
