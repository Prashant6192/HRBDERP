/**
 * Shapes and words shared by the intelligence screens: the reorder advice,
 * the slow-moving report, the expiry risk report and the outlook panel on
 * a material's page. Numbers arrive as strings (NUMERIC on the server).
 */

type Variant = 'success' | 'warning' | 'destructive' | 'muted' | 'info';

export type OutlookStatus = 'order_today' | 'order_soon' | 'watch' | 'ok';

export type VendorAdvice = {
    id: number | null;
    name: string | null;
    last_price: string | null;
    best_price: string | null;
    best_vendor: string | null;
    last_vendor?: string | null;
    lead_time_days: number | null;
    deliveries: number;
    last_delivery_at: string | null;
};

export type ItemOutlook = {
    item_id: number;
    code: string;
    name: string;
    type: string;
    unit: string;
    on_hand: string;
    reserved: string;
    usable: string;
    in_quarantine: string;
    daily_rate: string;
    rate_days: number;
    upcoming_requirement: string;
    horizon_demand: string;
    on_order: string;
    safety_stock: string | null;
    reorder_level: string | null;
    maximum_stock: string | null;
    lead_time_days: number;
    lead_time_source: 'item' | 'vendor' | 'default';
    days_of_cover: number | null;
    runs_out_at: string | null;
    needed_by: string | null;
    shortfall: string;
    recommended_quantity: string;
    order_by: string | null;
    status: OutlookStatus;
    vendor: VendorAdvice | null;
    sentences: string[];
    as_of: string;
};

export const OUTLOOK_LABEL: Record<OutlookStatus, string> = {
    order_today: 'Order today',
    order_soon: 'Order this week',
    watch: 'Watch',
    ok: 'Covered',
};

export const OUTLOOK_VARIANT: Record<OutlookStatus, Variant> = {
    order_today: 'destructive',
    order_soon: 'warning',
    watch: 'info',
    ok: 'success',
};

export type SlowMovingRow = {
    lot_id: number;
    batch_number: string;
    item_id: number;
    code: string;
    name: string;
    type: string;
    unit: string | null;
    warehouse: { id: number; code: string; name: string };
    on_hand: string;
    unit_cost: string | null;
    value: string | null;
    idle_since: string;
    idle_days: number;
    bucket: number;
    ever_issued: boolean;
    expiry_at: string | null;
};

export type SlowMovingReport = {
    buckets: { days: number; label: string; lots: number; value: string }[];
    total_value: string;
    rows: SlowMovingRow[];
};

export type ExpiryRiskLevel = 'expired' | 'high' | 'medium' | 'low';

export type ExpiryRiskRow = {
    lot_id: number;
    batch_number: string;
    item_id: number;
    code: string;
    name: string;
    type: string;
    unit: string | null;
    client_owned: boolean;
    in_quarantine: boolean;
    on_hand: string;
    expiry_at: string;
    days_left: number;
    daily_rate: string;
    expected_use: string;
    coverage_percent: string;
    at_risk_quantity: string;
    at_risk_value: string | null;
    level: ExpiryRiskLevel;
    sentence: string;
};

export type ExpiryRiskReport = {
    window_days: number;
    lots: number;
    at_risk_lots: number;
    at_risk_value: string;
    rows: ExpiryRiskRow[];
};

export const RISK_LABEL: Record<ExpiryRiskLevel, string> = {
    expired: 'Expired',
    high: 'High risk',
    medium: 'Some risk',
    low: 'Will be used',
};

export const RISK_VARIANT: Record<ExpiryRiskLevel, Variant> = {
    expired: 'destructive',
    high: 'destructive',
    medium: 'warning',
    low: 'success',
};

export type FacilityChip = { id: number; code: string; name: string };

/** ₹ with Indian grouping and no paise unless asked. */
export function rupees(
    value: string | number | null | undefined,
    fractionDigits = 0,
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const n = Number(value);

    if (!Number.isFinite(n)) {
        return '—';
    }

    return `₹${n.toLocaleString('en-IN', {
        minimumFractionDigits: fractionDigits,
        maximumFractionDigits: fractionDigits,
    })}`;
}

/** The page a material lives on, by its type. */
export function itemPath(type: string, id: number): string {
    switch (type) {
        case 'raw_material':
            return `/raw-materials/${id}`;
        case 'packaging_material':
            return `/packaging-materials/${id}`;
        default:
            return `/products/${id}`;
    }
}
