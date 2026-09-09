import type { FormulaStatus, FormulaVersionStatus } from '@/types';

type Variant = 'success' | 'warning' | 'destructive' | 'info' | 'muted';

export const FORMULA_STATUS_VARIANT: Record<FormulaStatus, Variant> = {
    draft: 'warning',
    active: 'success',
    archived: 'muted',
};

export const FORMULA_STATUS_LABEL: Record<FormulaStatus, string> = {
    draft: 'Draft',
    active: 'Active',
    archived: 'Archived',
};

export const VERSION_STATUS_VARIANT: Record<FormulaVersionStatus, Variant> = {
    draft: 'warning',
    active: 'success',
    superseded: 'muted',
    rejected: 'destructive',
};

export const VERSION_STATUS_LABEL: Record<FormulaVersionStatus, string> = {
    draft: 'Draft',
    active: 'Active',
    superseded: 'Superseded',
    rejected: 'Rejected',
};

/** Percentages as a chemist reads them: trailing zeros dropped, up to 4 places. */
export function pct(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (Number.isNaN(n)) {
        return value;
    }

    return `${Number(n.toFixed(4))}%`;
}

/** Sum of the numeric percentages on a set of lines, as a display string. */
export function sumPercent(values: (string | null | undefined)[]): number {
    return values.reduce<number>((total, v) => {
        const n = Number(v);
        return Number.isNaN(n) || v === '' || v === null || v === undefined
            ? total
            : total + n;
    }, 0);
}
