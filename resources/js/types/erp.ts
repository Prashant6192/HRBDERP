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
