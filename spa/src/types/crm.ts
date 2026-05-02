// Sprint 6 — CRM types. IDs are hash strings; decimals are strings.

export interface Product {
  id: string;
  part_number: string;
  name: string;
  description: string | null;
  unit_of_measure: string;
  standard_cost: string;
  is_active: boolean;
  has_bom: boolean;
  created_at: string;
  updated_at: string;
}

export interface PriceAgreement {
  id: string;
  product?: { id: string; part_number: string; name: string; unit_of_measure: string };
  customer?: { id: string; name: string };
  price: string;
  effective_from: string;
  effective_to: string;
  is_currently_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface CreateProductData {
  part_number: string;
  name: string;
  description?: string | null;
  unit_of_measure: string;
  standard_cost: string;
  is_active?: boolean;
}

export type UpdateProductData = Partial<CreateProductData>;

export interface CreatePriceAgreementData {
  product_id: string;
  customer_id: string;
  price: string;
  effective_from: string;
  effective_to: string;
}

export type UpdatePriceAgreementData = Partial<CreatePriceAgreementData>;

// ─── Sales orders (Task 48) ─────────────────────────────────────────────

export type SalesOrderStatus =
  | 'draft' | 'confirmed' | 'in_production' | 'partially_delivered'
  | 'delivered' | 'invoiced' | 'cancelled';

export interface SalesOrderItem {
  id: string;
  product?: { id: string; part_number: string; name: string; unit_of_measure: string };
  quantity: string;
  unit_price: string;
  total: string;
  quantity_delivered: string;
  remaining_quantity: string;
  delivery_date: string;
}

export interface SalesOrder {
  id: string;
  so_number: string;
  date: string;
  subtotal: string;
  vat_amount: string;
  total_amount: string;
  status: SalesOrderStatus;
  status_label: string;
  payment_terms_days: number;
  // Sprint 6 audit §3.2: linked chain context populated on the detail
  // payload only (whenLoaded on the resource).
  mrp_plan?: {
    id: string;
    mrp_plan_no: string;
    version: number;
    status: string;
    shortages_found: number;
    auto_pr_count: number;
    draft_wo_count: number;
  } | null;
  work_orders?: Array<{
    id: string;
    wo_number: string;
    status: string;
    quantity_target: number;
    quantity_produced: number;
    planned_start: string | null;
    product: { id: string; part_number: string; name: string } | null;
  }>;
  delivery_terms: string | null;
  notes: string | null;
  is_editable: boolean;
  is_cancellable: boolean;
  item_count: number;
  customer?: { id: string; name: string };
  creator?: { id: string; name: string };
  items?: SalesOrderItem[];
  created_at: string;
  updated_at: string;
}

export interface CreateSalesOrderItem {
  product_id: string;     // hash_id, decoded by ResolvesHashIds before validation
  quantity: string;
  delivery_date: string;
}

export interface CreateSalesOrderData {
  customer_id: string;
  date: string;
  payment_terms_days?: number;
  delivery_terms?: string;
  notes?: string;
  items: CreateSalesOrderItem[];
}

export type UpdateSalesOrderData = Partial<CreateSalesOrderData> & { items: CreateSalesOrderItem[] };

export interface SalesOrderChainStep {
  key: string;
  label: string;
  date: string | null;
  state: 'done' | 'active' | 'pending';
}
