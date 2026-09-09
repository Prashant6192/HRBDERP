import type { ReactNode } from 'react';
import type { FormulaAccess } from './erp';
import type { Auth } from '@/types/auth';
import type { BreadcrumbItem } from '@/types/navigation';

/**
 * The props every Inertia page receives, from HandleInertiaRequests::share.
 */
export type SharedData = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    formulaAccess: FormulaAccess | null;
    [key: string]: unknown;
};

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
