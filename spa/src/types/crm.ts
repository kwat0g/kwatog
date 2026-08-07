// Sprint 6 — CRM types. IDs are hash strings; decimals are strings.

export interface Product {
 id: string;
 part_number: string;
 name: string;
 description: string | null;
 unit_of_measure: string;
 standard_cost: string;
 is_active: boolean;
 include_forecast_in_mrp: boolean;
 has_bom: boolean;
 active_bom?: { id: string; version: number } | null;
 inspection_spec?: { id: string; version: number; updated_at: string | null } | null;
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
 next_statuses?: Array<{ value: string; label: string }>;
 payment_terms_days: number;
 // Sprint 6 audit §3.2: linked chain context populated on the detail
 // payload only (whenLoaded on the resource).
 mrp_plan?: {
 id: string;
 mrp_plan_no: string;
 version: number;
 status: string;
 status_label?: string;
 shortages_found: number;
 auto_pr_count: number;
 draft_wo_count: number;
 } | null;
 work_orders?: Array<{
 id: string;
 wo_number: string;
 status: string;
 status_label?: string;
 quantity_target: number;
 quantity_produced: number;
 planned_start: string | null;
 product: { id: string; part_number: string; name: string } | null;
 }>;
 inspections?: Array<{
 id: string; inspection_number: string; stage: string; stage_label?: string; status: string; status_label?: string; completed_at: string | null;
 }>;
 deliveries?: Array<{
 id: string; delivery_number: string; status: string; status_label?: string; scheduled_date: string | null;
 }>;
 invoices?: Array<{
 id: string; invoice_number: string; status: string; status_label?: string; total_amount: string; balance: string;
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
 product_id: string; // hash_id, decoded by ResolvesHashIds before validation
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
 severity_label?: string;
 status: ComplaintStatus;
 status_label?: string;
 description: string;
 affected_quantity: number;
 received_date: string | null;
 resolved_at: string | null;
 closed_at: string | null;
 customer?: { id: string; name: string } | null;
 product?: { id: string; part_number: string; name: string } | null;
 sales_order?: { id: string; so_number: string } | null;
 ncr?: { id: string; ncr_number: string; status: string; status_label?: string; severity: string; severity_label?: string } | null;
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

// ─── CA1 — Chain result returned from SO confirm ────────────────────

export interface SoChainResultWo {
 id: string;
 wo_number: string;
 product: { part_number: string; name: string } | null;
 status: string;
 quantity_target: number;
 machine: string | null;
 scheduled_start: string | null;
 scheduled_end: string | null;
 needs_manual_scheduling: boolean;
}

export interface SoChainResult {
 so_number: string;
 work_orders_created: number;
 auto_scheduled: number;
 needs_manual: number;
 shortages: number;
 prs_created: number;
 work_orders: SoChainResultWo[];
 scheduling_conflicts: Array<{ work_order_id: string; wo_number: string; reasons: string[] }>;
}

// ─── Sales pipeline (Leads → Opportunities → Quotes) ───────────────────────

export type LeadStatus = 'new' | 'contacted' | 'qualified' | 'disqualified' | 'converted';
export type LeadSource = 'referral' | 'website' | 'trade_show' | 'cold_call' | 'existing_customer' | 'other';
export type OpportunityStage =
 | 'prospecting' | 'needs_analysis' | 'proposal' | 'negotiation' | 'won' | 'lost';

export interface LeadAssignee {
 id: string;
 name: string;
}

export interface Lead {
 id: string;
 lead_number: string;
 company_name: string;
 contact_person: string;
 email: string | null;
 phone: string | null;
 source: LeadSource;
 source_label: string;
 status: LeadStatus;
 status_label: string;
 estimated_value: string | null;
 notes: string | null;
 converted_to_opportunity_id: string | null;
 assignee?: LeadAssignee | null;
 customer?: { id: string; name: string } | null;
 created_at: string;
 updated_at: string;
}

export interface Opportunity {
 id: string;
 opportunity_number: string;
 title: string;
 stage: OpportunityStage;
 stage_label: string;
 probability: number;
 estimated_value: string;
 expected_close_date: string | null;
 actual_close_date: string | null;
 lost_reason: string | null;
 notes: string | null;
 is_terminal: boolean;
 customer?: { id: string; name: string } | null;
 assignee?: LeadAssignee | null;
 lead?: { id: string; lead_number: string; company_name: string } | null;
 created_at: string;
 updated_at: string;
}

export interface CreateLeadData {
 company_name: string;
 contact_person: string;
 email?: string | null;
 phone?: string | null;
 source: LeadSource;
 estimated_value?: string | null;
 notes?: string | null;
 assigned_to?: string | null;
 customer_id?: string | null;
}

export type UpdateLeadData = Partial<CreateLeadData>;

export interface CreateOpportunityData {
 customer_id: string;
 lead_id?: string | null;
 title: string;
 stage?: OpportunityStage | null;
 probability?: number | null;
 estimated_value?: string | null;
 expected_close_date?: string | null;
 assigned_to?: string | null;
 notes?: string | null;
}

export type UpdateOpportunityData = Partial<CreateOpportunityData>;

/**
 * A submission from the public contact form (`/landing/contact-inquiry`).
 *
 * Kept out of `leads` on purpose: the form also catches job seekers and
 * supplier pitches, so promotion into the CRM funnel is an explicit action
 * rather than an automatic one.
 */
export type ContactInquiryStatus = 'new' | 'in_progress' | 'converted' | 'closed';

export interface ContactInquiry {
 id: string;
 inquiry_no: string;
 full_name: string;
 company: string | null;
 email: string;
 phone: string | null;
 message: string;
 status: ContactInquiryStatus;
 status_label: string;
 ip_address: string | null;
 user_agent: string | null;
 converted_to_lead?: { id: string; lead_number: string } | null;
 created_at: string;
 updated_at: string;
}
