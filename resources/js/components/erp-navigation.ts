import {
    Beaker,
    Boxes,
    Calculator,
    CalendarCheck,
    ClipboardCheck,
    ClipboardList,
    ClipboardPen,
    Factory,
    FlaskConical,
    LayoutGrid,
    Package,
    PackageCheck,
    ScrollText,
    ShieldCheck,
    Truck,
    Users,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { index as auditIndex } from '@/routes/audit';
import { index as formulasIndex } from '@/routes/formulas';
import { index as goodsReceiptsIndex } from '@/routes/goods-receipts';
import { index as lotsIndex } from '@/routes/lots';
import { index as manufacturingIndex } from '@/routes/manufacturing';
import { index as materialRequestsIndex } from '@/routes/material-requests';
import { index as packagingMaterialsIndex } from '@/routes/packaging-materials';
import { index as plansIndex } from '@/routes/plans';
import { index as productsIndex } from '@/routes/products';
import { index as qcIndex } from '@/routes/qc';
import { index as rawMaterialsIndex } from '@/routes/raw-materials';
import { index as rolesIndex } from '@/routes/roles';
import { index as stockIndex } from '@/routes/stock';
import { index as usersIndex } from '@/routes/users';
import { index as vendorsIndex } from '@/routes/vendors';
import { index as warehousesIndex } from '@/routes/warehouses';
import { dashboard } from '@/routes';

export type ErpNavItem = {
    title: string;
    href: string;
    icon: LucideIcon;
    /** The permission that makes this destination worth showing. */
    permission?: string;
    /** Match the current page on the full URL (path and query), not the path alone. */
    exact?: boolean;
    /** On the roadmap: shown so the shape of the system is visible, not yet a page. */
    comingSoon?: boolean;
};

export type ErpNavGroup = {
    label: string;
    items: ErpNavItem[];
};

/**
 * The ERP's navigation, in the order work flows through the factory:
 * store → quality → planning & purchase → manufacturing → packaging →
 * accounting → dispatch, with people and master data behind them.
 *
 * Each entry names the permission that makes it reachable. Entries the signed
 * -in user cannot use are not rendered — but that is presentation only. The
 * route behind each one authorises independently, so a hidden link is not a
 * closed door and is never treated as one.
 */
export const erpNavigation: ErpNavGroup[] = [
    {
        label: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: dashboard().url,
                icon: LayoutGrid,
            },
        ],
    },
    {
        label: 'Store',
        items: [
            {
                title: 'Raw Material Store',
                href: stockIndex({ query: { store: 'raw_material' } }).url,
                icon: FlaskConical,
                permission: 'inventory.view',
                exact: true,
            },
            {
                title: 'Packaging Store',
                href: stockIndex({ query: { store: 'packaging' } }).url,
                icon: Package,
                permission: 'inventory.view',
                exact: true,
            },
            {
                title: 'Finished Goods Store',
                href: stockIndex({ query: { store: 'finished_goods' } }).url,
                icon: Boxes,
                permission: 'inventory.view',
                exact: true,
            },
            {
                title: 'Goods Receipts',
                href: goodsReceiptsIndex().url,
                icon: PackageCheck,
                permission: 'purchase.view',
            },
            {
                title: 'Batches',
                href: lotsIndex().url,
                icon: ClipboardList,
                permission: 'inventory.view',
            },
        ],
    },
    {
        label: 'Quality Control',
        items: [
            {
                title: 'QC Checkpoint',
                href: qcIndex().url,
                icon: ClipboardCheck,
                permission: 'qc.view',
            },
        ],
    },
    {
        label: 'Planning & Purchase',
        items: [
            {
                title: 'Production Plans',
                href: plansIndex().url,
                icon: CalendarCheck,
                permission: 'planning.view',
            },
            {
                title: 'Material Requests',
                href: materialRequestsIndex().url,
                icon: ClipboardPen,
                permission: 'purchase.view',
            },
            {
                title: 'Vendors',
                href: vendorsIndex().url,
                icon: Truck,
                permission: 'vendor.view',
            },
        ],
    },
    {
        label: 'Manufacturing',
        items: [
            {
                title: 'Manufacturing Orders',
                href: manufacturingIndex().url,
                icon: Factory,
                permission: 'production.view',
                exact: true,
            },
            {
                title: 'Formulas',
                href: formulasIndex().url,
                icon: Beaker,
                permission: 'formula.view',
            },
        ],
    },
    {
        label: 'Packaging',
        items: [
            {
                title: 'Batches to Pack',
                href: manufacturingIndex({ query: { status: 'in_progress' } })
                    .url,
                icon: PackageCheck,
                permission: 'production.view',
                exact: true,
            },
        ],
    },
    {
        label: 'Accounting',
        items: [
            {
                title: 'Costing',
                href: '#',
                icon: Calculator,
                permission: 'costing.view',
                comingSoon: true,
            },
        ],
    },
    {
        label: 'Dispatch',
        items: [
            {
                title: 'Dispatch',
                href: '#',
                icon: Truck,
                permission: 'sales.view',
                comingSoon: true,
            },
        ],
    },
    {
        label: 'Human Resource',
        items: [
            {
                title: 'Employees',
                href: usersIndex().url,
                icon: Users,
                permission: 'user.view',
            },
        ],
    },
    {
        label: 'Master Data',
        items: [
            {
                title: 'Raw Materials',
                href: rawMaterialsIndex().url,
                icon: FlaskConical,
                permission: 'raw_material.view',
            },
            {
                title: 'Packaging Materials',
                href: packagingMaterialsIndex().url,
                icon: Package,
                permission: 'packaging_material.view',
            },
            {
                title: 'Products',
                href: productsIndex().url,
                icon: Boxes,
                permission: 'product.view',
            },
            {
                title: 'Warehouses',
                href: warehousesIndex().url,
                icon: Warehouse,
                permission: 'warehouse.view',
            },
        ],
    },
    {
        label: 'Administration',
        items: [
            {
                title: 'Roles & Permissions',
                href: rolesIndex().url,
                icon: ShieldCheck,
                permission: 'role.view',
            },
            {
                title: 'Audit Log',
                href: auditIndex().url,
                icon: ScrollText,
                permission: 'audit.view',
            },
        ],
    },
];
