import {
    Boxes,
    ClipboardCheck,
    ClipboardList,
    FlaskConical,
    Layers,
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
import { index as goodsReceiptsIndex } from '@/routes/goods-receipts';
import { index as lotsIndex } from '@/routes/lots';
import { index as qcIndex } from '@/routes/qc';
import { index as stockIndex } from '@/routes/stock';
import { index as packagingMaterialsIndex } from '@/routes/packaging-materials';
import { index as productsIndex } from '@/routes/products';
import { index as rawMaterialsIndex } from '@/routes/raw-materials';
import { index as rolesIndex } from '@/routes/roles';
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
};

export type ErpNavGroup = {
    label: string;
    items: ErpNavItem[];
};

/**
 * The ERP's navigation, grouped the way the business is organised.
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
                title: 'Stock',
                href: stockIndex().url,
                icon: Layers,
                permission: 'inventory.view',
            },
            {
                title: 'Goods Receipts',
                href: goodsReceiptsIndex().url,
                icon: PackageCheck,
                permission: 'purchase.view',
            },
            {
                title: 'Quality Control',
                href: qcIndex().url,
                icon: ClipboardCheck,
                permission: 'qc.view',
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
        label: 'Master Data',
        items: [
            {
                title: 'Raw Materials',
                href: rawMaterialsIndex().url,
                icon: FlaskConical,
                permission: 'raw_material.view',
            },
            {
                title: 'Packaging',
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
            {
                title: 'Vendors',
                href: vendorsIndex().url,
                icon: Truck,
                permission: 'vendor.view',
            },
        ],
    },
    {
        label: 'Administration',
        items: [
            {
                title: 'Users',
                href: usersIndex().url,
                icon: Users,
                permission: 'user.view',
            },
            {
                title: 'Roles',
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
