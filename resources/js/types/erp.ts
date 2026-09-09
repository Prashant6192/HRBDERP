/**
 * Shapes shared by every ERP screen.
 */

/** A Laravel length-aware paginator, as it arrives over Inertia. */
export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

/**
 * The list state the server actually applied.
 *
 * Echoed back rather than kept only in the browser, so the controls always
 * reflect the query that ran — including a sort column the server rejected
 * because it was not on its allow-list.
 */
export type TableState = {
    search: string;
    sort: string | null;
    direction: 'asc' | 'desc';
    per_page: number;
    filters: Record<string, string>;
};

export type SelectOption = {
    value: string | number;
    label: string;
    description?: string | null;
};

export type UomOption = SelectOption & {
    dimension: 'mass' | 'volume' | 'count';
    requires_item_factor: boolean;
};

export type Uom = {
    id: number;
    code: string;
    name: string;
    dimension: 'mass' | 'volume' | 'count';
    display_scale: number;
};

export type ItemCategory = {
    id: number;
    name: string;
};

export type Item = {
    id: number;
    code: string;
    name: string;
    type: string;
    description: string | null;
    category_id: number | null;
    category?: ItemCategory | null;
    stock_uom_id: number;
    stock_uom?: Uom | null;
    purchase_uom_id: number | null;
    purchase_uom?: Uom | null;
    net_content_uom_id: number | null;
    net_content_uom?: Uom | null;
    density_g_per_ml: string | null;
    hsn_code: string | null;
    gst_rate: string | null;
    standard_cost: string | null;
    brand: string | null;
    mrp: string | null;
    net_content: string | null;
    barcode: string | null;
    is_batch_tracked: boolean;
    requires_qc: boolean;
    shelf_life_days: number | null;
    reorder_level: string | null;
    minimum_stock: string | null;
    maximum_stock: string | null;
    lead_time_days: number | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
};

export type WarehouseLocation = {
    id: number;
    code: string;
    name: string;
    type: string;
    is_active: boolean;
};

export type Warehouse = {
    id: number;
    code: string;
    name: string;
    type: string;
    manager_id: number | null;
    manager?: { id: number; name: string } | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    pincode: string | null;
    country: string | null;
    gstin: string | null;
    is_quarantine: boolean;
    is_active: boolean;
    notes: string | null;
    locations?: WarehouseLocation[];
    locations_count?: number;
    created_at: string;
};

export type Vendor = {
    id: number;
    code: string;
    name: string;
    legal_name: string | null;
    gstin: string | null;
    pan: string | null;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    address_line_1: string | null;
    address_line_2: string | null;
    city: string | null;
    state: string | null;
    pincode: string | null;
    country: string | null;
    payment_terms_days: number | null;
    credit_limit: string | null;
    supply_type: string;
    is_approved: boolean;
    is_active: boolean;
    notes: string | null;
    created_at: string;
};

export type ErpUser = {
    id: number;
    employee_code: string | null;
    name: string;
    email: string;
    department_id: number | null;
    department?: { id: number; name: string } | null;
    designation: string | null;
    phone: string | null;
    status: 'active' | 'inactive' | 'suspended';
    last_login_at: string | null;
    deactivated_at: string | null;
    roles?: { id: number; name: string }[];
    created_at: string;
};

export type AuditEntry = {
    id: number;
    user_id: number | null;
    user_name: string | null;
    user_email: string | null;
    action: string;
    description: string | null;
    auditable_type: string | null;
    auditable_id: number | null;
    auditable_label: string | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    route: string | null;
    context: Record<string, unknown> | null;
    created_at: string;
};

// ---- Inventory, receiving and quality --------------------------------------

export type GoodsReceiptStatus = 'draft' | 'received' | 'cancelled';
export type LotQcStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'on_hold'
    | 'not_required';
export type StockAlertLevel =
    | 'healthy'
    | 'moderate'
    | 'low'
    | 'critical'
    | 'out_of_stock';

export type InventoryLot = {
    id: number;
    item_id: number;
    item?: Pick<Item, 'id' | 'code' | 'name' | 'type' | 'stock_uom_id'> & {
        stock_uom?: Pick<Uom, 'id' | 'code' | 'display_scale'> | null;
    };
    batch_number: string;
    supplier_batch_ref: string | null;
    vendor?: { id: number; name: string } | null;
    manufactured_at: string | null;
    received_at: string | null;
    expiry_at: string | null;
    qc_status: LotQcStatus;
    qc_decided_at: string | null;
    qc_decided_by?: { id: number; name: string } | null;
    initial_quantity: string;
    unit_cost: string | null;
    on_hand?: string | null;
    balances?: {
        id: number;
        on_hand: string;
        reserved: string;
        warehouse?: {
            id: number;
            code: string;
            name: string;
            is_quarantine: boolean;
        } | null;
    }[];
    notes: string | null;
    created_at: string;
};

export type GoodsReceiptLine = {
    id: number;
    item_id: number;
    item?: {
        id: number;
        code: string;
        name: string;
        requires_qc: boolean;
        stock_uom?: { id: number; code: string } | null;
    };
    quantity: string;
    uom?: { id: number; code: string } | null;
    stock_quantity: string;
    unit_price: string | null;
    supplier_batch_ref: string | null;
    manufactured_at: string | null;
    expiry_at: string | null;
    batch_number: string | null;
    lot?: Pick<
        InventoryLot,
        'id' | 'batch_number' | 'qc_status' | 'expiry_at'
    > | null;
    inspection?: { id: number; number: string; status: LotQcStatus } | null;
    notes: string | null;
};

export type GoodsReceipt = {
    id: number;
    number: string;
    vendor?: { id: number; name: string; code?: string } | null;
    warehouse?: { id: number; code: string; name: string } | null;
    received_at: string;
    invoice_ref: string | null;
    status: GoodsReceiptStatus;
    notes: string | null;
    received_by?: { id: number; name: string } | null;
    created_by?: { id: number; name: string } | null;
    posted_at: string | null;
    lines?: GoodsReceiptLine[];
    lines_count?: number;
    created_at: string;
};

export type QcInspection = {
    id: number;
    number: string;
    lot_id: number;
    lot?: InventoryLot;
    item?: {
        id: number;
        code: string;
        name: string;
        requires_qc?: boolean;
        shelf_life_days?: number | null;
        stock_uom?: { id: number; code: string; display_scale?: number } | null;
    };
    receipt_line?: {
        id: number;
        receipt?: {
            id: number;
            number: string;
            received_at: string;
            invoice_ref: string | null;
        } | null;
    } | null;
    quantity: string;
    status: LotQcStatus;
    destination_warehouse?: { id: number; code: string; name: string } | null;
    decided_by?: { id: number; name: string } | null;
    decided_at: string | null;
    remarks: string | null;
    parameters:
        | { name: string; value: string | null; passed: boolean | null }[]
        | null;
    created_at: string;
};

export type StockRow = {
    item_id: number;
    code: string;
    name: string;
    type: string;
    uom: string | null;
    display_scale: number;
    on_hand: string;
    reserved: string;
    available: string;
    reorder_level: string | null;
    minimum_stock: string | null;
    level: StockAlertLevel;
    level_label: string;
    severity: number;
};

export type StockMovement = {
    id: number;
    quantity: string;
    unit_cost: string | null;
    warehouse?: { id: number; code: string } | null;
    transaction?: {
        id: number;
        number: string;
        type: string;
        transacted_at: string;
        reason: string | null;
    } | null;
    created_at: string;
};
