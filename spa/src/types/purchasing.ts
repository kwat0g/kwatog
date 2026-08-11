// Sprint 5 — Purchasing types.

export type PurchaseRequestStatus =
 | 'draft' | 'pending' | 'approved' | 'rejected' | 'converted' | 'cancelled';
export type PurchaseRequestConversionStatus =
 | 'not_started' | 'pending' | 'manual_required' | 'converted';
export type PurchaseRequestPriority = 'normal' | 'urgent' | 'critical';
export type PurchaseOrderStatus =
 | 'draft' | 'pending_approval' | 'approved' | 'sent'
 | 'partially_received' | 'received' | 'closed' | 'cancelled';
export type SupplierDispatchStatus =
 | 'pending' | 'portal_available' | 'manual_required' | 'confirmed' | 'failed' | 'cancelled';

export interface ApprovalRecord {
 step_order: number;
 role_slug: string;
 action: 'pending' | 'approved' | 'rejected' | 'skipped';
 remarks: string | null;
 acted_at: string | null;
 /** Sprint P3 — populated when approver:id,name is eager-loaded. */
 approver?: { id: string; name: string } | null;
 is_overdue?: boolean;
 overdue_hours?: number | null;
}

export interface PurchaseRequestItem {
 id: string;
 item: { id: string; code: string; name: string; unit_of_measure: string } | null;
 description: string;
 quantity: string;
 unit: string | null;
 estimated_unit_price: string | null;
 estimated_total: string;
 purpose: string | null;
 suggested_vendor?: { id: string; name: string } | null;
 suggested_vendor_id?: string | null;
}

export interface PurchaseRequest {
 id: string;
 pr_number: string;
 date: string;
 reason: string | null;
 priority: PurchaseRequestPriority;
 priority_label?: string;
 status: PurchaseRequestStatus;
 status_label?: string;
 po_conversion_status: PurchaseRequestConversionStatus;
 po_conversion_status_label?: string;
 po_conversion_note: string | null;
 po_conversion_at: string | null;
 is_auto_generated: boolean;
 auto_generated_reason: string | null;
 is_urgent: boolean;
 urgency_reason: string | null;
 has_overdue_approval: boolean;
 current_approval_step: number;
 submitted_at: string | null;
 approved_at: string | null;
 budget_warning_level?: string | null;
 budget_warning_message?: string | null;
 budget_acknowledged_at?: string | null;
 total_estimated_amount: string;
 requester: { id: string; name: string } | null;
 department: { id: string; name: string; code: string } | null;
 template: { id: number; name: string } | null;
 items?: PurchaseRequestItem[];
 approval_records?: ApprovalRecord[]; purchase_orders?: Array<{
  id: string; po_number: string; status: PurchaseOrderStatus; status_label?: string;
  vendor: { id: string; name: string } | null;
  total_amount: string;
  is_auto_generated?: boolean;  bill?: { id: string; bill_number: string; status: string; status_label?: string; total_amount: string } | null;
  grns?: Array<{ id: string; grn_number: string; status: string }>;
}>;
}

export interface PurchaseRequestTemplate {
 id: string;
 name: string;
 department: { id: string; name: string; code: string } | null;
 items: Array<{
 item_id?: string | null;
 description: string;
 quantity: string;
 unit?: string | null;
 estimated_unit_price?: string | null;
 }>;
 notes: string | null;
 created_by: string | null;
 is_active: boolean;
 created_at: string | null;
}

export interface CreatePurchaseRequestData {
 department_id?: string;
 date?: string;
 reason?: string;
 priority?: PurchaseRequestPriority;
 is_urgent?: boolean;
 urgency_reason?: string;
 template_id?: string;
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
 is_billable?: boolean;
 requires_vp_approval: boolean;
 is_auto_generated: boolean;
 has_overdue_approval: boolean;
 current_approval_step: number;
 approved_at: string | null;
 sent_to_supplier_at: string | null;
 supplier_dispatch?: {
  status: SupplierDispatchStatus;
  status_label?: string;
  channel: string | null;
  attempts: number;
  recipient_count: number;
  queued_at: string | null;
  last_attempt_at: string | null;
  published_at: string | null;
  confirmed_at: string | null;
  last_error?: string | null;
  metadata?: Record<string, unknown> | null;
 } | null;
 budget_warning_level?: string | null;
 budget_warning_message?: string | null;
 budget_acknowledged_at?: string | null;
 remarks: string | null;
 quantity_received_pct: number;
 vendor: { id: string; name: string; contact_person: string | null; email: string | null } | null;
 purchase_request: { id: string; pr_number: string } | null;
 items?: PurchaseOrderItem[];
 goods_receipt_notes?: Array<{ id: string; grn_number: string; received_date: string; status: string; status_label?: string }>;
 bills?: Array<{ id: string; bill_number: string; total_amount: string; balance: string; status: string; status_label?: string; due_date?: string | null; has_variances?: boolean; three_way_overridden?: boolean }>;
 approval_records?: ApprovalRecord[];
 creator?: { id: string; name: string } | null;
 approver?: { id: string; name: string } | null;
}

export interface CreatePurchaseOrderData {
 vendor_id: string;
 purchase_request_id?: string;
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

/* ─── ADV5 — Procurement Chain Overview ─────────────────────────── */

export interface ProcurementChainOverview {
 material_requirements: {
 pr_pending: number;
 pr_approved: number;
 po_draft: number;
 po_sent: number;
 po_partially_received: number;
 po_received: number;
 };
 receiving: {
 grn_received: number;
 grn_pending_qc: number;
 };
 billing: {
 bills_unpaid: number;
 bills_overdue: number;
 bills_this_month: string;
 };
 three_way_match: {
 matched: number;
 has_variances: number;
 overridden: number;
 };
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
 po_price_variance_pct?: number;
 grn_price_variance_pct?: number;
 // H-6 added 'grn_short' to flag a bill line that exceeds accepted GRN qty.
 status: 'matched' | 'qty_variance' | 'price_variance' | 'both' | 'grn_short' | 'unmatched_bill_line' | 'duplicate_bill_line';
 status_label?: string;
 severity: 'ok' | 'block';
 // H-6 — present from the API when the GRN gate fired.
 grn_status?: 'ok' | 'short';
 }>;
 overall_status: 'matched' | 'has_variances' | 'blocked';
 tolerances: { qty_pct: number; price_pct: number };
}
