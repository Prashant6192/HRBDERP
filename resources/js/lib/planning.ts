import type {
    MaterialRequestStatus,
    ProductionPlanStatus,
    StoreKind,
} from '@/types';

type Variant = 'success' | 'warning' | 'destructive' | 'info' | 'muted';

export const PLAN_STATUS_LABEL: Record<ProductionPlanStatus, string> = {
    draft: 'Draft',
    checked: 'Checked',
    requested: 'Requests raised',
    in_production: 'In production',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const PLAN_STATUS_VARIANT: Record<ProductionPlanStatus, Variant> = {
    draft: 'muted',
    checked: 'info',
    requested: 'warning',
    in_production: 'info',
    completed: 'success',
    cancelled: 'destructive',
};

export const PMR_STATUS_LABEL: Record<MaterialRequestStatus, string> = {
    open: 'Open',
    partially_received: 'Partly received',
    fulfilled: 'Fulfilled',
    cancelled: 'Cancelled',
};

export const PMR_STATUS_VARIANT: Record<MaterialRequestStatus, Variant> = {
    open: 'warning',
    partially_received: 'info',
    fulfilled: 'success',
    cancelled: 'muted',
};

export const STORE_LABEL: Record<StoreKind, string> = {
    raw_material: 'Raw Material Store',
    packaging: 'Packaging Material Store',
};

export const STORE_SHORT: Record<StoreKind, string> = {
    raw_material: 'RM',
    packaging: 'PM',
};
