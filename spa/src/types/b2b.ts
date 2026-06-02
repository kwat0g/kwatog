/** Portal user types for ADV10 — B2B Portals */

export interface SupplierPortalUser {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  vendor: { id: string; name: string } | null;
  last_login_at: string | null;
  created_at: string;
}

export interface CustomerPortalUser {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  company_name: string | null;
  customer: { id: string; name: string } | null;
  last_login_at: string | null;
  created_at: string;
}

// ── Supplier portal: Purchase Order types ─────────────

export interface PortalPoSummary {
  id: string;
  po_number: string;
  date: string | null;
  total_amount: string;
  status: string;
  expected_delivery_date: string | null;
  sent_to_supplier_at: string | null;
}

export interface PortalPoItem {
  id: string;
  part_number: string;
  name: string;
  quantity_ordered: number;
  quantity_received: number;
  unit_price: string;
  total_price: string;
}

export interface PortalPoDetail extends PortalPoSummary {
  items: PortalPoItem[];
  goods_receipt_notes: Array<{
    id: string;
    grn_number: string;
    received_date: string | null;
  }>;
  bills: Array<{
    id: string;
    bill_number: string;
    total_amount: string;
    paid_amount: string;
    balance: string;
    status: string;
    due_date: string | null;
  }>;
}

// ── Customer portal: Sales Order types ────────────────

export interface PortalSoSummary {
  id: string;
  so_number: string;
  date: string | null;
  total_amount: string;
  status: string;
  status_label?: string;
  created_at: string;
}

export interface PortalSoItem {
  id: string;
  part_number: string;
  name: string;
  quantity: number;
  unit_price: string;
  total_price: string;
}

export interface PortalSoDetail extends PortalSoSummary {
  items?: PortalSoItem[];
  work_orders?: Array<{
    id: string;
    wo_number: string;
    status: string;
    quantity_target: number;
    quantity_produced: number;
    planned_start: string | null;
  }>;
  customer?: { id: string; name: string };
  notes?: string;
  payment_terms_days?: number;
  delivery_terms?: string;
}

// ── Shared portal types ──────────────────────────────

export interface PortalInvoiceSummary {
  id: string;
  invoice_number: string;
  date: string | null;
  total_amount: string;
  balance: string;
  status: string;
  due_date: string | null;
}

export interface PortalInvoiceDetail extends PortalInvoiceSummary {
  items: Array<{
    id: string;
    description: string;
    quantity: number;
    unit_price: string;
    total_price: string;
  }>;
  payments: Array<{
    id: string;
    amount: string;
    paid_at: string | null;
    payment_method: string;
  }>;
}

export interface PortalDeliverySummary {
  id: string;
  delivery_number: string;
  delivered_at: string | null;
  status: string;
  scheduled_date?: string | null;
  sales_order?: { id: string; so_number: string } | null;
}

export interface PortalDeliveryDetail extends PortalDeliverySummary {
  items: Array<{
    id: string;
    part_number: string;
    name: string;
    quantity_delivered: number;
  }>;
  proofs: Array<{
    id: string;
    proof_type: string;
    file_name: string;
    view_url: string | null;
    notes: string | null;
  }>;
  receiver_name?: string | null;
  driver?: { id: string; name: string } | null;
}

export interface PortalCoCSummary {
  id: string;
  coc_number: string;
  work_order: string;
  batch_number: string | null;
  inspection_date: string | null;
}

export interface PortalPaymentSummary {
  id: string;
  amount: string;
  paid_at: string | null;
  payment_method: string;
  reference: string | null;
  invoice?: { id: string; invoice_number: string; total_amount?: string } | null;
  bill?: { id: string; bill_number: string; total_amount?: string } | null;
}

// ── Portal Shipping Documents ─────────────────────────

export interface PortalShippingDocument {
  id: string;
  purchase_order_id: number;
  document_type: string;
  document_type_label: string;
  original_filename: string;
  file_size_bytes: number;
  file_size_formatted: string;
  mime_type: string | null;
  notes: string | null;
  uploaded_by: number | null;
  uploaded_at: string | null;
  download_url: string;
}

// ── Supplier Invoice Submission ───────────────────────

export interface SubmittedBill {
  id: string;
  bill_number: string;
  total_amount: string;
  status: string;
}

export interface PortalComplaint {
  id: string;
  complaint_number: string;
  severity: string;
  status: string;
  description: string;
  affected_quantity: number;
  received_date: string | null;
  resolved_at: string | null;
  created_at: string;
}

// ── 8D Report (customer portal) ──────────────────────

export interface EightDReportData {
  complaint_number: string;
  complaint_status: string;
  severity: string;
  description: string;
  report: {
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
  } | null;
}

// ── Statement of Account ─────────────────────────────

export interface StatementOfAccount {
  customer_name: string | null;
  total_outstanding: string;
  aging_buckets: {
    current: string;
    d1_30: string;
    d31_60: string;
    d61_90: string;
    d91_plus: string;
  };
  open_invoices: PortalInvoiceSummary[];
  as_of_date: string;
}

// ── Delivery Schedule ────────────────────────────────

export interface DeliveryScheduleLine {
  product_name: string;
  quantity: number;
  notes?: string;
}

export interface DeliverySchedule {
  id: string;
  month: string;
  status: string;
  lines: DeliveryScheduleLine[];
  purchase_order?: { id: string; po_number: string } | null;
  created_at: string;
  updated_at: string;
}

// ── Vendor Statement of Account (Supplier Portal) ─────

export interface VendorStatementOfAccount {
  vendor_name: string | null;
  total_outstanding: string;
  aging_buckets: {
    current: string;
    d1_30: string;
    d31_60: string;
    d61_90: string;
    d91_plus: string;
  };
  open_bills: Array<{
    id: string;
    bill_number: string;
    date: string | null;
    due_date: string | null;
    total_amount: string;
    balance: string;
    status: string;
    is_overdue: boolean;
    aging_bucket: string;
    purchase_order?: { id: string; po_number: string } | null;
  }>;
  as_of_date: string;
}

// ── Supplier portal dashboard ─────────────────────────

export interface SupplierDashboardData {
  open_po_count: number;
  pending_delivery_count: number;
  unpaid_invoice_count: number;
  total_unpaid_amount: string;
  recent_pos: PortalPoSummary[];
  recent_invoices: PortalInvoiceSummary[];
}

// ── Customer portal dashboard ─────────────────────────

export interface CustomerDashboardData {
  open_so_count: number;
  pending_delivery_count: number;
  open_invoice_count: number;
  total_outstanding: string;
  recent_orders: PortalSoSummary[];
  recent_invoices: PortalInvoiceSummary[];
  recent_deliveries: PortalDeliverySummary[];
  recent_complaints: PortalComplaint[];
}
