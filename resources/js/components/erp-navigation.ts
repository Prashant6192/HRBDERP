import {
    ArrowLeftRight,
    BarChart3,
    Bot,
    Beaker,
    Boxes,
    Building2,
    Calculator,
    CalendarX2,
    CircleDollarSign,
    FlaskRound,
    Gauge,
    CalendarCheck,
    ClipboardCheck,
    ClipboardList,
    ClipboardPen,
    FileCheck2,
    Factory,
    FlaskConical,
    Handshake,
    LayoutGrid,
    MonitorDot,
    Package,
    PackageCheck,
    PackagePlus,
    PackageX,
    ScrollText,
    ShieldCheck,
    ShoppingCart,
    Signature,
    Truck,
    Users,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { index as auditIndex } from '@/routes/audit';
import { index as clientsIndex } from '@/routes/clients';
import { index as countsIndex } from '@/routes/counts';
import { index as openingStockIndex } from '@/routes/opening-stock';
import { index as formulasIndex } from '@/routes/formulas';
import { index as goodsReceiptsIndex } from '@/routes/goods-receipts';
import { index as lotsIndex } from '@/routes/lots';
import { index as manufacturingIndex } from '@/routes/manufacturing';
import { index as materialRequestsIndex } from '@/routes/material-requests';
import { index as packagingMaterialsIndex } from '@/routes/packaging-materials';
import { index as plansIndex } from '@/routes/plans';
import {
    capacity as planningCapacity,
    simulate as planningSimulate,
} from '@/routes/planning';
import { production as analyticsProduction } from '@/routes/analytics';
import { profitability as clientsProfitability } from '@/routes/clients';
import { index as productsIndex } from '@/routes/products';
import { index as qcIndex } from '@/routes/qc';
import { index as rawMaterialsIndex } from '@/routes/raw-materials';
import { index as rolesIndex } from '@/routes/roles';
import { expiryRisk, index as stockIndex, slowMoving } from '@/routes/stock';
import { reorderAdvice } from '@/routes/purchase';
import { index as usersIndex } from '@/routes/users';
import { index as vendorsIndex } from '@/routes/vendors';
import { index as facilitiesIndex } from '@/routes/facilities';
import { index as transfersIndex } from '@/routes/transfers';
import { index as warehousesIndex } from '@/routes/warehouses';
import { commandCentre, dashboard, scorecards } from '@/routes';
import { index as assistantIndex } from '@/routes/assistant';
import { index as approvalsIndex } from '@/routes/approvals';
import { index as documentsIndex } from '@/routes/documents';

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
    /** Reserved for the system administrator, whatever permissions others hold. */
    superAdminOnly?: boolean;
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
            {
                title: 'Command Centre',
                href: commandCentre().url,
                icon: MonitorDot,
                permission: 'report.view',
            },
            {
                title: 'Scorecards',
                href: scorecards().url,
                icon: Gauge,
                permission: 'report.view',
            },
            {
                title: 'Ask the ERP',
                href: assistantIndex().url,
                icon: Bot,
                permission: 'assistant.view',
            },
            {
                title: 'Approvals',
                href: approvalsIndex().url,
                icon: Signature,
                permission: 'approval.view',
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
            {
                title: 'Stock Transfers',
                href: transfersIndex().url,
                icon: ArrowLeftRight,
                permission: 'inventory.view',
            },
            {
                title: 'Old Stock Entry',
                href: openingStockIndex().url,
                icon: PackagePlus,
                superAdminOnly: true,
            },
            {
                title: 'Stock Counts',
                href: countsIndex().url,
                icon: ClipboardCheck,
                permission: 'inventory.view',
            },
            {
                title: 'Slow-moving Stock',
                href: slowMoving().url,
                icon: PackageX,
                permission: 'inventory.view',
            },
            {
                title: 'Expiry Risk',
                href: expiryRisk().url,
                icon: CalendarX2,
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
            {
                title: 'Controlled Documents',
                href: documentsIndex().url,
                icon: FileCheck2,
                permission: 'document.view',
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
                title: 'Reorder Advice',
                href: reorderAdvice().url,
                icon: ShoppingCart,
                permission: 'purchase.view',
            },
            {
                title: 'What-if Simulation',
                href: planningSimulate().url,
                icon: FlaskRound,
                permission: 'planning.view',
            },
            {
                title: 'Capacity',
                href: planningCapacity().url,
                icon: Gauge,
                permission: 'planning.view',
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
            {
                title: 'Production Analytics',
                href: analyticsProduction().url,
                icon: BarChart3,
                permission: 'production.view',
            },
        ],
    },
    {
        label: 'Third-Party Manufacturing',
        items: [
            {
                title: 'Third-Party Jobs',
                href: manufacturingIndex({ query: { type: 'third_party' } })
                    .url,
                icon: Handshake,
                permission: 'production.view',
                exact: true,
            },
            {
                title: 'Contract Clients',
                href: clientsIndex().url,
                icon: Building2,
                permission: 'client.view',
            },
            {
                title: 'Client Profitability',
                href: clientsProfitability().url,
                icon: CircleDollarSign,
                permission: 'costing.view',
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
                title: 'Facilities & Warehouses',
                href: facilitiesIndex().url,
                icon: Building2,
                permission: 'facility.view',
            },
            {
                title: 'Stores',
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
