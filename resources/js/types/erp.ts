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
    inci_name: string | null;
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

// ---- Formulations ----------------------------------------------------------

export type FormulaStatus = 'draft' | 'active' | 'archived';

export type FormulaVersionStatus =
    | 'draft'
    | 'active'
    | 'superseded'
    | 'rejected';

/** What the sidebar and the formula screens know about the second factor. */
export type FormulaAccess = {
    unlocked: boolean;
    expires_at: string | null;
    minutes_remaining: number;
    needs_pin: boolean;
    ttl_minutes: number;
    require_pin: boolean;
};

export type FormulaSummary = {
    id: number;
    code: string;
    name: string;
    status: FormulaStatus;
    product_id: number | null;
    product?: { id: number; code: string; name: string } | null;
    active_version_id: number | null;
    active_version?: {
        id: number;
        version_number: number;
        activated_at: string | null;
    } | null;
    versions_count?: number;
    description: string | null;
    created_by?: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
};

export type FormulaVersionSummary = {
    id: number;
    version_number: number;
    status: FormulaVersionStatus;
    total_percentage: string;
    batch_size: string;
    batch_uom: string | null;
    change_summary: string | null;
    source: string;
    created_at: string | null;
    created_by: string | null;
    activated_at: string | null;
    approved_by: string | null;
    superseded_at: string | null;
};

export type FormulaVersionDetail = {
    id: number;
    formula_id: number;
    version_number: number;
    status: FormulaVersionStatus;
    batch_size: string;
    batch_uom_id: number;
    batch_uom?: { id: number; code: string } | null;
    total_percentage: string;
    notes: string | null;
    change_summary: string | null;
    source: string;
    source_reference: string | null;
    activated_at: string | null;
    created_at: string;
};

export type FormulaIngredientRow = {
    id: number;
    line_no: number;
    item_id: number;
    item_code: string;
    item_name: string;
    inci_name: string | null;
    stock_uom: string | null;
    percentage: string | null;
    is_qs: boolean;
    qs_note: string | null;
    as_required: boolean;
    grade: string | null;
    phase: string | null;
    purpose: string | null;
    notes: string | null;
};

export type ScaledLine = {
    line_no: number;
    item_id: number;
    item_code: string;
    item_name: string;
    inci_name: string | null;
    grade: string | null;
    purpose: string | null;
    percentage: string | null;
    is_qs: boolean;
    as_required: boolean;
    quantity: string | null;
    batch_uom: string;
    stock_quantity: string | null;
    stock_uom: string;
    converted: boolean;
    assumed_density: boolean;
};

export type ScaledBatch = {
    formula_version_id: number;
    batch_quantity: string;
    batch_uom: string;
    fixed_percentage: string;
    qs_percentage: string | null;
    complete: boolean;
    lines: ScaledLine[];
};

export type ImportPlanLine = {
    line_no: number;
    key: string;
    name: string;
    inci_name: string | null;
    trade_name: string | null;
    percentage: string | null;
    is_qs: boolean;
    qs_note: string | null;
    grade: string | null;
    purpose: string | null;
    as_required: boolean;
    item_id: number | null;
    item_code: string | null;
    action: 'match' | 'create';
    warnings: string[];
};

export type ImportPlanFormula = {
    sheet: string;
    name: string;
    layout: 'tabular' | 'vertical';
    batch_size: string;
    batch_uom: string;
    action:
        | 'create'
        | 'new_version'
        | 'skip_duplicate'
        | 'skip_identical'
        | 'skip_draft';
    existing_formula_id: number | null;
    existing_code: string | null;
    product_id: number | null;
    product_name: string | null;
    total_percentage: string;
    has_qs: boolean;
    lines: ImportPlanLine[];
    warnings: string[];
};

export type ImportPlan = {
    formulas: ImportPlanFormula[];
    skipped_sheets: string[];
    summary: {
        create: number;
        new_version: number;
        skip: number;
        materials_to_create: number;
    };
};

export type PendingImport = {
    token: string;
    file_name: string;
    options: { assume_water_qs: boolean; activate: boolean };
    plan: ImportPlan;
};

// ---- Planning & Purchase ---------------------------------------------------

export type ProductionPlanStatus =
    | 'draft'
    | 'checked'
    | 'requested'
    | 'in_production'
    | 'completed'
    | 'cancelled';

export type MaterialRequestStatus =
    | 'open'
    | 'partially_received'
    | 'fulfilled'
    | 'cancelled';

export type StoreKind = 'raw_material' | 'packaging';

export type ProductPackagingLine = {
    id: number;
    packaging_material_id: number;
    code: string;
    name: string;
    uom: string | null;
    quantity_per_unit: string;
    notes: string | null;
};

export type ProductionPlan = {
    id: number;
    number: string;
    formula_id: number;
    formula?: { id: number; code: string; name: string } | null;
    formula_version_id: number;
    formula_version?: {
        id: number;
        version_number: number;
        batch_size: string;
        batch_uom?: { id: number; code: string } | null;
    } | null;
    product_id: number | null;
    product?: {
        id: number;
        code: string;
        name: string;
        net_content?: string | null;
        net_content_uom?: { id: number; code: string } | null;
    } | null;
    planned_quantity: string;
    planned_uom?: { id: number; code: string } | null;
    planned_units: number | null;
    status: ProductionPlanStatus;
    planned_start_date: string | null;
    notes: string | null;
    warnings: string[] | null;
    created_by?: { id: number; name: string } | null;
    checked_at: string | null;
    requested_at: string | null;
    cancelled_at: string | null;
    created_at: string;
    short_lines_count?: number;
    material_requests_count?: number;
};

export type RequirementLineRow = {
    id: number;
    line_no: number;
    store_kind: StoreKind;
    item_id: number;
    item_code: string;
    item_name: string;
    item_type: string;
    uom: string;
    percentage: string | null;
    is_qs: boolean;
    as_required: boolean;
    required: string;
    available: string;
    shortage: string;
    restock: string;
    level_now: StockAlertLevel;
    level_after: StockAlertLevel;
    reorder_level: string | null;
    minimum_stock: string | null;
    notes: string[];
};

export type MaterialRequestSummary = {
    id: number;
    number: string;
    store_kind: StoreKind;
    store_label: string;
    warehouse: string | null;
    status: MaterialRequestStatus;
    status_label: string;
    needed_by: string | null;
};

export type MaterialRequest = {
    id: number;
    number: string;
    production_plan_id: number;
    plan?: {
        id: number;
        number: string;
        formula?: { id: number; code: string; name: string } | null;
        product?: { id: number; code: string; name: string } | null;
        planned_quantity: string;
        planned_uom?: { id: number; code: string } | null;
        planned_units?: number | null;
        planned_start_date?: string | null;
        status?: ProductionPlanStatus;
    } | null;
    store_kind: StoreKind;
    warehouse?: { id: number; code: string; name: string } | null;
    status: MaterialRequestStatus;
    needed_by: string | null;
    notes: string | null;
    requested_by?: { id: number; name: string } | null;
    requested_at: string;
    fulfilled_at: string | null;
    cancelled_at: string | null;
    lines_count?: number;
    short_lines_count?: number;
    goods_receipts?: {
        id: number;
        number: string;
        status: string;
        received_at: string;
    }[];
};

export type MaterialRequestLineRow = {
    id: number;
    line_no: number;
    item_id: number;
    item_code: string;
    item_name: string;
    uom: string;
    required: string;
    available: string;
    to_order: string;
    restock: string;
    received: string;
    outstanding: string;
    covered: boolean;
    alert_level: StockAlertLevel;
};

// ---- Manufacturing ---------------------------------------------------------

export type ManufacturingOrderStatus =
    | 'draft'
    | 'approved'
    | 'in_progress'
    | 'completed'
    | 'cancelled';

export type ManufacturingOrder = {
    id: number;
    number: string;
    production_plan_id: number | null;
    plan?: { id: number; number: string; status?: ProductionPlanStatus } | null;
    formula_id: number;
    formula?: { id: number; code: string; name: string } | null;
    formula_version?: { id: number; version_number: number } | null;
    product_id: number | null;
    product?: {
        id: number;
        code: string;
        name: string;
        requires_qc?: boolean;
        shelf_life_days?: number | null;
        stock_uom?: { id: number; code: string; dimension: string } | null;
    } | null;
    planned_quantity: string;
    planned_uom?: { id: number; code: string } | null;
    planned_units: number | null;
    status: ManufacturingOrderStatus;
    output_quantity: string | null;
    output_units: number | null;
    yield_percentage: string | null;
    output_lot_id: number | null;
    output_lot?: {
        id: number;
        batch_number: string;
        qc_status: LotQcStatus;
        expiry_at: string | null;
        initial_quantity: string;
    } | null;
    manufactured_at: string | null;
    notes: string | null;
    created_by?: { id: number; name: string } | null;
    approved_by?: { id: number; name: string } | null;
    completed_by?: { id: number; name: string } | null;
    approved_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    cancelled_at: string | null;
    created_at: string;
};

export type ManufacturingOrderLineRow = {
    id: number;
    line_no: number;
    store_kind: StoreKind;
    item_id: number;
    item_code: string;
    item_name: string;
    item_type: string;
    uom: string;
    percentage: string | null;
    is_qs: boolean;
    as_required: boolean;
    planned: string;
    reserved: string;
    consumed: string;
};

export type ReservationRow = {
    id: number;
    item_id: number;
    lot: string | null;
    expiry_at: string | null;
    warehouse: string | null;
    quantity: string;
    consumed: string;
    status: 'active' | 'consumed' | 'released';
};

export type ManufacturingOrderSummary = {
    id: number;
    number: string;
    status: ManufacturingOrderStatus;
    status_label: string;
    started_at: string | null;
    completed_at: string | null;
};
