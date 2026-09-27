import {
    ArrowDownToLine,
    ArrowUpFromLine,
    BarChart3,
    Beaker,
    Bot,
    Boxes,
    Building2,
    CalendarCheck,
    CalendarX2,
    CircleDollarSign,
    ClipboardCheck,
    ClipboardPen,
    DatabaseZap,
    Factory,
    FileCheck2,
    FlaskConical,
    FlaskRound,
    Gauge,
    Handshake,
    LayoutGrid,
    MonitorDot,
    Package,
    PackageCheck,
    PackageX,
    ScrollText,
    ShieldCheck,
    ShoppingBag,
    ShoppingCart,
    Signature,
    Store,
    Tag,
    Tags,
    Truck,
    Undo2,
    Upload,
    Users,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { data as dataAdministration } from '@/routes/administration';
import { production as analyticsProduction } from '@/routes/analytics';
import { index as approvalsIndex } from '@/routes/approvals';
import { index as assistantIndex } from '@/routes/assistant';
import { index as auditIndex } from '@/routes/audit';
import { index as brandsIndex } from '@/routes/brands';
import {
    index as clientsIndex,
    profitability as clientsProfitability,
} from '@/routes/clients';
import { index as customersIndex } from '@/routes/customers';
import { index as dispatchesIndex } from '@/routes/dispatches';
import { index as documentsIndex } from '@/routes/documents';
import { index as facilitiesIndex } from '@/routes/facilities';
import { index as formulasIndex } from '@/routes/formulas';
import { index as listingsIndex } from '@/routes/listings';
import { index as lotsIndex } from '@/routes/lots';
import { index as manufacturingIndex } from '@/routes/manufacturing';
import { index as materialRequestsIndex } from '@/routes/material-requests';
import {
    create as onlineOrdersUpload,
    index as onlineOrdersIndex,
} from '@/routes/online-orders';
import { index as returnsIndex } from '@/routes/online-orders/returns';
import { index as packagingMaterialsIndex } from '@/routes/packaging-materials';
import {
    capacity as planningCapacity,
    simulate as planningSimulate,
} from '@/routes/planning';
import { index as plansIndex } from '@/routes/plans';
import { index as productsIndex } from '@/routes/products';
import { reorderAdvice } from '@/routes/purchase';
import { index as qcIndex } from '@/routes/qc';
import { index as rawMaterialsIndex } from '@/routes/raw-materials';
import { index as receiveIndex } from '@/routes/receive';
import { index as rolesIndex } from '@/routes/roles';
import { expiryRisk, index as stockIndex, slowMoving } from '@/routes/stock';
import { index as transfersIndex } from '@/routes/transfers';
import { index as usersIndex } from '@/routes/users';
import { index as vendorsIndex } from '@/routes/vendors';
import { index as warehousesIndex } from '@/routes/warehouses';
import { commandCentre, dashboard, scorecards } from '@/routes';

export type PlaceKind = 'factory' | 'depot' | 'contract' | 'company' | 'labels';

/** A place the ERP is arranged by, as the server describes it. */
export type Place = {
    key: string;
    kind: PlaceKind;
    facility_id: number | null;
    name: string;
    short: string;
};

export type ErpNavItem = {
    title: string;
    href: string;
    icon: LucideIcon;
    /** The permission that makes this destination worth showing. */
    permission?: string;
    /** Reserved for the system administrator, whatever permissions others hold. */
    superAdminOnly?: boolean;
    /** Key of the live count shown beside it, if any. */
    count?: string;
    /** Other words people search for it by. */
    keywords?: string;
};

export type ErpNavGroup = {
    label: string;
    items: ErpNavItem[];
};

export const PLACE_ICON: Record<PlaceKind, LucideIcon> = {
    factory: Factory,
    depot: Warehouse,
    contract: Handshake,
    company: Building2,
    labels: ShoppingBag,
};

/**
 * The departments and pages of one place, in the order work flows there.
 *
 * Each entry names the permission that makes it reachable, and entries a
 * person cannot use are not shown — but that is presentation only. The
 * route behind every one authorises on its own, so a hidden link is never
 * treated as a closed door.
 */
export function placeGroups(place: Place, places: Place[]): ErpNavGroup[] {
    const f = place.facility_id;
    const fq = f ? { facility: f } : {};

    switch (place.kind) {
        case 'factory': {
            const depots = places.filter((p) => p.kind === 'depot');
            const sendTo =
                depots.length === 1
                    ? `Send to ${depots[0].short}`
                    : 'Send to depots';

            return [
                overview(fq, true),
                {
                    label: 'Stores',
                    items: [
                        {
                            title: 'Raw Material Store',
                            href: stockIndex({
                                query: { store: 'raw_material', ...fq },
                            }).url,
                            icon: FlaskConical,
                            permission: 'inventory.view',
                            count: 'store.raw_material',
                            keywords: 'rm ingredients chemicals stock',
                        },
                        {
                            title: 'Packaging Store',
                            href: stockIndex({
                                query: { store: 'packaging', ...fq },
                            }).url,
                            icon: Package,
                            permission: 'inventory.view',
                            count: 'store.packaging',
                            keywords: 'pm bottles caps cartons stock',
                        },
                        {
                            title: 'Finished Goods Store',
                            href: stockIndex({
                                query: { store: 'finished_goods', ...fq },
                            }).url,
                            icon: Boxes,
                            permission: 'inventory.view',
                            count: 'store.finished_goods',
                            keywords: 'fg stock',
                        },
                        {
                            title: sendTo,
                            href: transfersIndex({
                                query: { ...fq, direction: 'out' },
                            }).url,
                            icon: ArrowUpFromLine,
                            permission: 'inventory.view',
                            count: 'transfers.out',
                            keywords: 'stock transfer lorry challan',
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
                            count: 'qc',
                            keywords: 'quality inspection release',
                        },
                        {
                            title: 'Carton labels',
                            href: lotsIndex({
                                query: {
                                    type: 'finished_good',
                                    qc_status: 'approved',
                                },
                            }).url,
                            icon: Tags,
                            permission: 'inventory.view',
                            keywords: 'stickers print tsc box batch',
                        },
                        {
                            title: 'Controlled Documents',
                            href: documentsIndex().url,
                            icon: FileCheck2,
                            permission: 'document.view',
                            keywords: 'sop',
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
                            keywords: 'pmr purchase',
                        },
                        {
                            title: 'Reorder Advice',
                            href: reorderAdvice().url,
                            icon: ShoppingCart,
                            permission: 'purchase.view',
                            keywords: 'buy order low',
                        },
                        {
                            title: 'Vendors',
                            href: vendorsIndex().url,
                            icon: Truck,
                            permission: 'vendor.view',
                            keywords: 'suppliers',
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
                            count: 'manufacturing',
                            keywords: 'mo batch production',
                        },
                        {
                            title: 'Batches to Pack',
                            href: manufacturingIndex({
                                query: { status: 'in_progress' },
                            }).url,
                            icon: PackageCheck,
                            permission: 'production.view',
                            keywords: 'packaging packing',
                        },
                        {
                            title: 'Formulas',
                            href: formulasIndex().url,
                            icon: Beaker,
                            permission: 'formula.view',
                            keywords: 'recipe formulation',
                        },
                        {
                            title: 'Production Analytics',
                            href: analyticsProduction().url,
                            icon: BarChart3,
                            permission: 'production.view',
                            keywords: 'yield cost',
                        },
                    ],
                },
            ];
        }

        case 'depot':
            return [
                overview(fq, false),
                {
                    label: 'Receiving',
                    items: [
                        {
                            title: 'Receive by scan',
                            href: receiveIndex({ query: fq }).url,
                            icon: ArrowDownToLine,
                            permission: 'inventory.receive_transfer',
                            count: 'receive',
                            keywords: 'carton scan lorry inward',
                        },
                        {
                            title: 'Incoming transfers',
                            href: transfersIndex({
                                query: { ...fq, direction: 'in' },
                            }).url,
                            icon: Truck,
                            permission: 'inventory.view',
                            count: 'transfers.in',
                            keywords: 'stock transfer from factory challan',
                        },
                        {
                            title: 'Finished Goods Store',
                            href: stockIndex({
                                query: { store: 'finished_goods', ...fq },
                            }).url,
                            icon: Boxes,
                            permission: 'inventory.view',
                            count: 'store.finished_goods',
                            keywords: 'fg stock',
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
                    label: 'Online Orders',
                    items: [
                        {
                            title: 'Online orders',
                            href: onlineOrdersIndex({ query: fq }).url,
                            icon: ShoppingBag,
                            permission: 'marketplace.view',
                            count: 'online',
                            keywords: 'meesho amazon flipkart labels pack',
                        },
                        {
                            title: 'Returns',
                            href: returnsIndex().url,
                            icon: Undo2,
                            permission: 'marketplace.return',
                            keywords: 'rto',
                        },
                        {
                            title: 'SKU mapping',
                            href: listingsIndex().url,
                            icon: Tags,
                            permission: 'marketplace.manage',
                            keywords: 'listings',
                        },
                        {
                            title: 'Brands',
                            href: brandsIndex().url,
                            icon: Tag,
                            permission: 'marketplace.manage',
                        },
                    ],
                },
                {
                    label: 'Dispatch',
                    items: [
                        {
                            title: 'Dispatches',
                            href: dispatchesIndex({ query: fq }).url,
                            icon: Truck,
                            permission: 'dispatch.view',
                            count: 'dispatches',
                            keywords: 'invoice e-way bill',
                        },
                        {
                            title: 'Customers',
                            href: customersIndex().url,
                            icon: Store,
                            permission: 'dispatch.view',
                            keywords: 'distributors',
                        },
                    ],
                },
            ];

        case 'contract':
            return [
                {
                    label: 'Contract Work',
                    items: [
                        {
                            title: 'Third-Party Jobs',
                            href: manufacturingIndex({
                                query: { type: 'third_party' },
                            }).url,
                            icon: Handshake,
                            permission: 'production.view',
                            count: 'contract.jobs',
                            keywords: '3p client batches',
                        },
                        {
                            title: 'Third-Party Plans',
                            href: plansIndex({
                                query: { type: 'third_party' },
                            }).url,
                            icon: CalendarCheck,
                            permission: 'planning.view',
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
                            keywords: 'margin',
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
            ];

        case 'company':
            return [
                {
                    label: 'People',
                    items: [
                        {
                            title: 'Employees',
                            href: usersIndex().url,
                            icon: Users,
                            permission: 'user.view',
                            keywords: 'staff users accounts',
                        },
                        {
                            title: 'Roles & Permissions',
                            href: rolesIndex().url,
                            icon: ShieldCheck,
                            permission: 'role.view',
                        },
                    ],
                },
                {
                    label: 'Masters',
                    items: [
                        {
                            title: 'Raw Materials',
                            href: rawMaterialsIndex().url,
                            icon: FlaskConical,
                            permission: 'raw_material.view',
                            keywords: 'rm hsn reorder',
                        },
                        {
                            title: 'Packaging Materials',
                            href: packagingMaterialsIndex().url,
                            icon: Package,
                            permission: 'packaging_material.view',
                            keywords: 'pm',
                        },
                        {
                            title: 'Products',
                            href: productsIndex().url,
                            icon: Boxes,
                            permission: 'product.view',
                            keywords: 'sku mrp finished goods',
                        },
                        {
                            title: 'Facilities & Warehouses',
                            href: facilitiesIndex().url,
                            icon: Building2,
                            permission: 'facility.view',
                            keywords: 'opening stock',
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
                            title: 'Audit Log',
                            href: auditIndex().url,
                            icon: ScrollText,
                            permission: 'audit.view',
                            keywords: 'history',
                        },
                        {
                            title: 'Data',
                            href: dataAdministration().url,
                            icon: DatabaseZap,
                            superAdminOnly: true,
                            keywords: 'backup restore',
                        },
                    ],
                },
            ];

        case 'labels':
            return [
                {
                    label: 'Labels',
                    items: [
                        {
                            title: 'Online orders',
                            href: onlineOrdersIndex().url,
                            icon: ShoppingBag,
                            permission: 'marketplace.view',
                        },
                        {
                            title: 'Upload labels',
                            href: onlineOrdersUpload().url,
                            icon: Upload,
                            permission: 'marketplace.upload',
                        },
                    ],
                },
            ];
    }
}

function overview(fq: { facility?: number }, factory: boolean): ErpNavGroup {
    return {
        label: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: dashboard({ query: fq }).url,
                icon: LayoutGrid,
                keywords: 'home today',
            },
            {
                title: 'Command Centre',
                href: commandCentre().url,
                icon: MonitorDot,
                permission: 'report.view',
                keywords: 'exceptions alerts',
            },
            ...(factory
                ? [
                      {
                          title: 'Scorecards',
                          href: scorecards().url,
                          icon: Gauge,
                          permission: 'report.view',
                          keywords: 'otif kpi',
                      },
                  ]
                : []),
            {
                title: 'Approvals',
                href: approvalsIndex().url,
                icon: Signature,
                permission: 'approval.view',
                count: 'approvals',
            },
            {
                title: 'Ask the ERP',
                href: assistantIndex().url,
                icon: Bot,
                permission: 'assistant.view',
                keywords: 'ai assistant question',
            },
        ],
    };
}

/** The pseudo-place an outside agency works in: its labels, nothing else. */
export const LABELS_PLACE: Place = {
    key: 'labels',
    kind: 'labels',
    facility_id: null,
    name: 'Marketplace labels',
    short: 'Labels',
};
