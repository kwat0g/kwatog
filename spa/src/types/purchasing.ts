// Sprint 5 — Purchasing types.

export type PurchaseRequestStatus =
  | 'draft' | 'pending' | 'approved' | 'rejected' | 'converted' | 'cancelled';
export type PurchaseRequestPriority = 'normal' | 'urgent' | 'critical';
export type PurchaseOrderStatus =
  | 'draft' | 'pending_approval' | 'approved' | 'sent'
  | 'partially_received' | 'received' | 'closed' | 'cancelled';

export interface ApprovalRecord {
  step_order: number;
  role_slug: string;
  action: 'pending' | 'approved' | 'rejected' | 'skipped';
  remarks: string | null;
  acted_at: string | null;
}

export interface PurchaseRequestItem {
  id: number;
  item: { id: string; code: string; name: string; unit_of_measure: string } | null;
  description: string;
  quantity: string;
  unit: string | null;
  estimated_unit_price: string | null;
  estimated_total: string;
  purpose: string | null;
}

export interface PurchaseRequest {
  id: string;
  pr_number: string;
  date: string;
  reason: string | null;
  priority: PurchaseRequestPriority;
  status: PurchaseRequestStatus;
  is_auto_generated: boolean;
  current_approval_step: number;
  submitted_at: string | null;
  approved_at: string | null;
  total_estimated_amount: string;
  requester: { id: string; name: string } | null;
  department: { id: string; name: string; code: string } | null;
  items?: PurchaseRequestItem[];
  approval_records?: ApprovalRecord[];
  purchase_orders?: Array<{
    id: string; po_number: string; status: PurchaseOrderStatus;
    vendor: { id: string; name: string } | null;
    total_amount: string;
  }>;
}

export interface CreatePurchaseRequestData {
  department_id?: number;
  date?: string;
  reason?: string;
  priority?: PurchaseRequestPriority;
  items: Array<{
    item_id?: string | null;
    description: string;
    quantity: string;
    unit?: string;
    estimated_unit_price?: string;
    purpose?: string;
  }>;
}

export interface PurchaseOrderItem {
  id: number;
  purchase_request_item_id: number | null;
  item: { id: string; code: string; name: string; unit_of_measure: string };
  description: string;
  quantity: string;
  unit: string | null;
  unit_price: string;
  total: string;
  quantity_received: string;
  quantity_remaining: string;
}

export interface PurchaseOrder {
  id: string;
  po_number: string;
  date: string;
  expected_delivery_date: string | null;
  subtotal: string;
  vat_amount: string;
  total_amount: string;
  is_vatable: boolean;
  status: PurchaseOrderStatus;
  requires_vp_approval: boolean;
  current_approval_step: number;
  approved_at: string | null;
  sent_to_supplier_at: string | null;
  remarks: string | null;
  quantity_received_pct: number;
  vendor: { id: string; name: string; contact_person: string | null; email: string | null } | null;
  purchase_request: { id: string; pr_number: string } | null;
  items?: PurchaseOrderItem[];
  goods_receipt_notes?: Array<{ id: string; grn_number: string; received_date: string; status: string }>;
  bills?: Array<{ id: string; bill_number: string; total_amount: string; balance: string; status: string }>;
  approval_records?: ApprovalRecord[];
  creator?: { id: string; name: string } | null;
  approver?: { id: string; name: string } | null;
}

export interface CreatePurchaseOrderData {
  vendor_id: string;
  date?: string;
  expected_delivery_date?: string;
  is_vatable?: boolean;
  remarks?: string;
  items: Array<{
    item_id: string;
    description: string;
    quantity: string;
    unit?: string;
    unit_price: string;
  }>;
}

export interface ApprovedSupplier {
  id: string;
  item: { id: string; code: string; name: string };
  vendor: { id: string; name: string };
  is_preferred: boolean;
  lead_time_days: number;
  last_price: string | null;
  last_price_at: string | null;
}

export interface ThreeWayMatchResult {
  po_id: number;
  po_number: string;
  lines: Array<{
    item_id: number;
    item_code: string | null;
    description: string;
    po_quantity: string;
    po_unit_price: string;
    po_total: string;
    grn_quantity_accepted: string;
    grn_unit_cost: string;
    bill_quantity: string;
    bill_unit_price: string;
    bill_total: string;
    quantity_variance_pct: number;
    price_variance_pct: number;
    status: 'matched' | 'qty_variance' | 'price_variance' | 'both';
    severity: 'ok' | 'block';
  }>;
  overall_status: 'matched' | 'has_variances' | 'blocked';
  tolerances: { qty_pct: number; price_pct: number };
}
