import {
    Boxes,
    ClipboardList,
    FlaskConical,
    LayoutGrid,
    Package,
    ScrollText,
    ShieldCheck,
    Truck,
    Users,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { index as auditIndex } from '@/routes/audit';
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

/**
 * Placeholder icon export kept for modules still to come, so that adding
 * Inventory or Production later is a one-line change here.
 */
export const upcomingModuleIcon = ClipboardList;
