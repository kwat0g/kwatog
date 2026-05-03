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

// ─── Sprint 7 Task 68 — Customer Complaints + 8D ────────────────────

export type ComplaintStatus = 'open' | 'investigating' | 'resolved' | 'closed' | 'cancelled';
export type ComplaintSeverity = 'low' | 'medium' | 'high' | 'critical';

export interface EightDReport {
  id: string;
  d1_team: string | null;
  d2_problem: string | null;
  d3_containment: string | null;
  d4_root_cause: string | null;
  d5_corrective_action: string | null;
  d6_verification: string | null;
  d7_prevention: string | null;
  d8_recognition: string | null;
  finalized_at: string | null;
}

export interface CustomerComplaint {
  id: string;
  complaint_number: string;
  severity: ComplaintSeverity;
  status: ComplaintStatus;
  description: string;
  affected_quantity: number;
  received_date: string | null;
  resolved_at: string | null;
  closed_at: string | null;
  customer?: { id: string; name: string } | null;
  product?: { id: string; part_number: string; name: string } | null;
  sales_order?: { id: string; so_number: string } | null;
  ncr?: { id: string; ncr_number: string; status: string; severity: string } | null;
  creator?: { id: string; name: string } | null;
  assignee?: { id: string; name: string } | null;
  eight_d_report?: EightDReport | null;
  created_at: string;
  updated_at: string;
}

export interface CreateComplaintData {
  customer_id: string;
  product_id?: string | null;
  sales_order_id?: string | null;
  received_date: string;
  severity: ComplaintSeverity;
  description: string;
  affected_quantity?: number;
  assigned_to?: string | null;
}
