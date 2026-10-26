/**
 * รูปร่าง response ของ Laravel API v1 — ยอดเงินทุกตัวเป็นจำนวนเต็มหน่วยสตางค์ (…_satang)
 */

export interface User {
    id: string;
    name: string;
    email: string;
    is_platform_admin: boolean;
}

export interface StoreSummary {
    id: string;
    name: string;
    code: string;
    status: string;
}

export interface TenantSummary {
    id: string;
    name: string;
    slug: string;
    status: string;
    stores: StoreSummary[];
}

export interface LoginResponse {
    token: string;
    token_type: 'Bearer';
    expires_at: string | null;
    user: User;
}

export interface MeResponse {
    user: User;
    tenants: TenantSummary[];
}

export interface StoreDetail extends StoreSummary {
    timezone: string;
    settings: { kitchen_enabled?: boolean; tax_mode?: 'none' | 'inclusive'; [key: string]: unknown } | null;
}

export interface Context {
    tenant: { id: string; name: string };
    store: { id: string; name: string; timezone: string } | null;
    permissions: string[];
    server_time: string;
}

export interface ModifierOption {
    id: string;
    name: string;
    price_delta_satang: number;
}

export interface ModifierGroup {
    id: string;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
    options: ModifierOption[];
}

export interface MenuProduct {
    id: string;
    category_id: string | null;
    sku: string | null;
    name: string;
    description: string | null;
    price_satang: number;
    modifier_groups: ModifierGroup[];
}

export interface Menu {
    categories: { id: string; name: string; products: MenuProduct[] }[];
    uncategorized: MenuProduct[];
}

export type OrderType = 'dine_in' | 'takeaway';

export type OrderStatus = 'draft' | 'placed' | 'preparing' | 'ready' | 'served' | 'completed' | 'cancelled';

export interface OrderItem {
    id: string;
    product_id: string;
    name: string;
    sku: string | null;
    unit_price_satang: number;
    modifiers_total_satang: number;
    quantity: number;
    discount_satang: number;
    tax_satang: number;
    line_total_satang: number;
    notes: string | null;
    status: 'active' | 'voided';
    modifiers: { modifier_option_id: string; group: string; name: string; price_delta_satang: number; quantity: number }[];
}

export type KitchenTicketStatus = 'queued' | 'preparing' | 'ready' | 'served' | 'cancelled';

export interface KitchenTicket {
    id: string;
    order_id: string;
    /** มีเมื่อโหลดพร้อม order (API ของจอครัว) */
    order_number?: number;
    order_type?: OrderType;
    table_label?: string | null;
    revision: number;
    is_addition: boolean;
    status: KitchenTicketStatus;
    sent_at: string | null;
    acknowledged_at?: string | null;
    ready_at?: string | null;
    served_at?: string | null;
    cancelled_at?: string | null;
    cancel_reason?: string | null;
    updated_at?: string | null;
    items?: {
        id: string;
        order_item_id: string;
        name: string;
        quantity: number;
        modifiers: { group: string; name: string; quantity: number }[] | null;
        notes: string | null;
    }[];
}

export interface KitchenTicketPage {
    data: KitchenTicket[];
    has_more: boolean;
    next_cursor: string | null;
    server_time: string;
    poll_interval_seconds: number;
    late_threshold_minutes: number;
}

export type PaymentStatus =
    | 'pending'
    | 'processing'
    | 'succeeded'
    | 'failed'
    | 'cancelled'
    | 'expired'
    | 'partially_refunded'
    | 'refunded';

export interface Payment {
    id: string;
    order_id: string;
    method: 'cash' | 'qr';
    amount_satang: number;
    tendered_satang: number | null;
    change_satang: number | null;
    refunded_satang: number;
    status: PaymentStatus;
    paid_at: string | null;
    created_at: string | null;
    slip?: { submitted_at: string | null; rejection: { reason?: string; [key: string]: unknown } | null } | null;
}

export interface QrInstruction {
    payload: string | null;
    image_url: string | null;
    provider: string;
    requires_slip: boolean;
    reference: string | null;
    expires_at: string | null;
}

export interface Order {
    id: string;
    store_id: string;
    business_date: string | null;
    order_number: number;
    order_type: OrderType;
    dining_table_id: string | null;
    table_label: string | null;
    status: OrderStatus;
    subtotal_satang: number;
    discount_satang: number;
    tax_satang: number;
    total_satang: number;
    paid_satang?: number;
    balance_due_satang?: number;
    refund_due_satang?: number;
    notes: string | null;
    created_at: string | null;
    items?: OrderItem[];
    kitchen_tickets?: KitchenTicket[];
    payments?: Payment[];
}

export interface CashierShift {
    id: string;
    status: 'open' | 'closed';
    opening_float_satang: number;
    expected_cash_satang: number | null;
    counted_cash_satang: number | null;
    variance_satang: number | null;
    opened_at: string | null;
    closed_at: string | null;
}

export interface Receipt {
    store: { name: string; code: string };
    order_number: number;
    business_date: string;
    order_type: OrderType;
    table_label: string | null;
    printed_at: string;
    lines: { name: string; quantity: number; modifiers: string[]; line_total: string }[];
    subtotal: string;
    discount: string;
    tax: string;
    tax_included: boolean;
    amount_before_tax: string;
    total: string;
    payments: { method: 'cash' | 'qr'; amount: string; tendered: string | null; change: string | null }[];
}

/** แต่ละ item ที่ส่งให้ server — ส่งเฉพาะ intent ราคา server คำนวณเอง */
export interface OrderItemInput {
    product_id: string;
    quantity: number;
    modifier_option_ids: string[];
    notes: string | null;
}

// ---------- Back-office ----------

export interface Category {
    id: string;
    name: string;
    sort_order: number;
    is_active: boolean;
}

export interface Product {
    id: string;
    category_id: string | null;
    sku: string | null;
    name: string;
    description: string | null;
    base_price_satang: number;
    status: 'active' | 'inactive';
    sort_order: number;
    modifier_group_ids?: string[];
}

export interface ManagedModifierGroup {
    id: string;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
    sort_order: number;
    options: { id: string; name: string; price_delta_satang: number; is_active: boolean; sort_order: number }[];
}

export interface ManagedStore extends StoreDetail {
    qr_payment_account_id: string | null;
}

export type RoleSlug = 'owner' | 'manager' | 'cashier' | 'kitchen';

export interface Member {
    membership_id: string;
    user: { id: string; name: string; email: string; status: string };
    status: string;
    roles: { role: string; store_id: string | null }[];
    store_ids: string[];
}

export type PaymentProviderCode = 'omise' | 'easyslip';

export interface PaymentAccount {
    id: string;
    provider_code: PaymentProviderCode;
    name: string;
    status: 'active' | 'inactive';
    settings: Record<string, string> | null;
    configured_credentials: string[];
    webhook_url: string;
    created_at: string | null;
}

export interface DailySalesRow {
    business_date: string;
    completed_orders: number;
    cancelled_orders: number;
    subtotal_satang: number;
    discount_satang: number;
    tax_satang: number;
    total_satang: number;
    payments: { cash: number; qr: number };
    refunds_satang: number;
}

export interface DailySalesReport {
    store_id: string;
    timezone: string;
    from: string;
    to: string;
    days: DailySalesRow[];
}

export interface ProductSalesReport {
    store_id: string;
    from: string;
    to: string;
    products: { product_id: string | null; name: string; quantity: number; revenue_satang: number }[];
}

export interface DiningTable {
    id: string;
    name: string;
    zone: string | null;
    seats: number | null;
    sort_order: number;
    is_active: boolean;
    /** null = โต๊ะว่าง */
    open_order: { id: string; order_number: number; status: OrderStatus; total_satang: number; created_at: string | null } | null;
}

/** ราคา/การขายเฉพาะสาขา — สินค้าที่ไม่มีรายการนี้ใช้ราคาตั้งต้นของสินค้า */
export interface StorePrice {
    product_id: string;
    price_satang: number;
    is_available: boolean;
}
