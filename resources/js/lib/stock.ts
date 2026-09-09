import type { LotQcStatus, StockAlertLevel } from '@/types';

type Variant = 'success' | 'warning' | 'destructive' | 'muted' | 'info';

/** Mirrors StockAlertLevel::badgeVariant() on the server. */
export const ALERT_VARIANT: Record<StockAlertLevel, Variant> = {
    healthy: 'success',
    moderate: 'info',
    low: 'warning',
    critical: 'destructive',
    out_of_stock: 'destructive',
};

export const ALERT_LABEL: Record<StockAlertLevel, string> = {
    healthy: 'Healthy',
    moderate: 'Moderate',
    low: 'Low',
    critical: 'Critical',
    out_of_stock: 'Out of stock',
};

/** Mirrors LotQcStatus::badgeVariant() on the server. */
export const QC_VARIANT: Record<LotQcStatus, Variant> = {
    pending: 'warning',
    approved: 'success',
    not_required: 'success',
    rejected: 'destructive',
    on_hold: 'muted',
};

export const QC_LABEL: Record<LotQcStatus, string> = {
    pending: 'Awaiting QC',
    approved: 'QC approved',
    not_required: 'No QC required',
    rejected: 'QC rejected',
    on_hold: 'On hold',
};

/** Format a NUMERIC string for display without going through a float for storage. */
export function qty(
    value: string | number | null | undefined,
    scale = 3,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return String(value);
    }

    return n.toLocaleString('en-IN', {
        minimumFractionDigits: 0,
        maximumFractionDigits: scale,
    });
}

export function date(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
