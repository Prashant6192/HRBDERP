import type {
    ArtworkStatus,
    FormulaOwnership,
    ManufacturingType,
    MaterialSource,
} from '@/types';

type Variant = 'success' | 'warning' | 'destructive' | 'info' | 'muted';

export const MANUFACTURING_TYPE_LABEL: Record<ManufacturingType, string> = {
    own: 'Own brand',
    third_party: 'Third party',
};

export const OWNERSHIP_LABEL: Record<FormulaOwnership, string> = {
    company: 'Company owned',
    client: 'Client owned',
    joint: 'Joint / contract',
};

export const OWNERSHIP_VARIANT: Record<FormulaOwnership, Variant> = {
    company: 'muted',
    client: 'warning',
    joint: 'info',
};

export const MATERIAL_SOURCE_LABEL: Record<MaterialSource, string> = {
    company: 'Our material',
    client: 'Client supplied',
    mixed: 'Mixed',
};

export const ARTWORK_STATUS_LABEL: Record<ArtworkStatus, string> = {
    pending: 'Awaiting approval',
    approved: 'Approved',
    superseded: 'Superseded',
    rejected: 'Rejected',
};

export const ARTWORK_STATUS_VARIANT: Record<ArtworkStatus, Variant> = {
    pending: 'warning',
    approved: 'success',
    superseded: 'muted',
    rejected: 'destructive',
};

export const ARTWORK_KIND_LABEL: Record<string, string> = {
    label: 'Label',
    carton: 'Carton',
    bottle: 'Bottle',
    other: 'Other',
};

/** Rupees, Indian grouping, two decimals. */
export function money(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);

    if (!Number.isFinite(n)) {
        return '—';
    }

    return `₹${n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
