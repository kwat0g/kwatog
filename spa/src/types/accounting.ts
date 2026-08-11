// Sprint 4 — Lean Accounting types.
// IDs are HashID strings, never numbers. Decimal money values are strings.

export type AccountType = 'asset' | 'liability' | 'equity' | 'revenue' | 'expense';
export type NormalBalance = 'debit' | 'credit';

export interface Account {
 id: string;
 code: string;
 name: string;
 type: AccountType;
 type_label?: string;
 normal_balance: NormalBalance;
 normal_balance_label?: string;
 parent_id: string | null;
 parent_code?: string | null;
 is_active: boolean;
 is_leaf?: boolean | null;
 description: string | null;
 children?: Account[];
 /** Populated by /accounts/tree only. */
 current_balance?: string | null;
 total_debit?: string | null;
 total_credit?: string | null;
 created_at?: string;
 updated_at?: string;
}

export interface CreateAccountData {
 code: string;
 name: string;
 type: AccountType;
 normal_balance?: NormalBalance;
 parent_id?: string | null;
 description?: string;
}

export type UpdateAccountData = Partial<CreateAccountData> & { is_active?: boolean };

export type JournalEntryStatus = 'draft' | 'posted' | 'reversed';

export interface JournalEntryLine {
 line_no: number;
 debit: string;
 credit: string;
 description: string | null;
 account?: { id: string; code: string; name: string; type: AccountType; normal_balance: NormalBalance } | null;
}

export interface JournalEntry {
 id: string;
 entry_number: string;
 date: string;
 description: string;
 reference_type: string | null;
 reference_id: number | null;
 reference_label: string | null;
 total_debit: string;
 total_credit: string;
 status: JournalEntryStatus;
 status_label?: string;
 reversed_by_entry_id: string | null;
 reversed_by_number?: string | null;
 posted_at: string | null;
 posted_by?: { id?: string; name?: string } | null;
 created_by?: { id?: string; name?: string } | null;
 lines?: JournalEntryLine[];
}

export interface CreateJournalEntryLineData {
 account_id: string;
 debit: string;
 credit: string;
 description?: string;
}

export interface CreateJournalEntryData {
 date: string;
 description: string;
 reference_type?: string | null;
 reference_id?: number | null;
 lines: CreateJournalEntryLineData[];
}

export interface Vendor {
 id: string;
 name: string;
 contact_person: string | null;
 email: string | null;
 phone: string | null;
 address: string | null;
 tin: string | null;
 payment_terms_days: number;
 is_active: boolean;
 open_balance?: string | null;
 bills_count?: number;
}

export interface CreateVendorData {
 name: string;
 contact_person?: string;
 email?: string;
 phone?: string;
 address?: string;
 tin?: string;
 payment_terms_days?: number;
 is_active?: boolean;
}
export type UpdateVendorData = Partial<CreateVendorData>;

export type BillStatus = 'draft' | 'unpaid' | 'partial' | 'paid' | 'cancelled';
export type PaymentMethod = 'cash' | 'check' | 'bank_transfer' | 'online';

export interface BillItem {
 id: number;
 description: string;
 quantity: string;
 unit: string | null;
 unit_price: string;
 total: string;
 expense_account?: { id: string; code: string; name: string } | null;
}

export interface BillPayment {
 id: string;
 payment_date: string;
 amount: string;
 payment_method: PaymentMethod;
 payment_method_label?: string;
 reference_number: string | null;
 cash_account?: { id: string; code: string; name: string } | null;
 journal_entry_id: string | null;
 created_at?: string;
}

export interface Bill {
 id: string;
 bill_number: string;
 date: string;
 due_date: string;
 is_vatable: boolean;
 subtotal: string;
 vat_amount: string;
 total_amount: string;
 amount_paid: string;
 balance: string;
 status: BillStatus;
 /** 2026-08-08 — GRN this bill was auto-created from (draft auto-bills). */
 goods_receipt_note_id?: string | null;
 status_label?: string;
 is_overdue: boolean;
 aging_bucket: string;
 remarks: string | null;
 vendor?: { id: string; name: string } | null;
 items?: BillItem[];
 payments?: BillPayment[];
 journal_entry?: { id: string; entry_number: string; status: JournalEntryStatus; status_label?: string } | null;
 // REC-02 — 3-way match linkage (present when the bill is tied to a PO).
 purchase_order?: {
  id: string;
  po_number: string;
  /** 2026-08-08 — P2P stepper: the PR behind this PO. */
  purchase_request?: { id: string; pr_number: string } | null;
 } | null;
 /** 2026-08-08 — P2P stepper: the source receipt(s) this bill came from. */
 goods_receipt_notes?: Array<{ id: string; grn_number: string; status: string }>;
 has_variances?: boolean;
 three_way_overridden?: boolean;
 three_way_override_reason?: string | null;
 three_way_review_status?: 'not_applicable' | 'matched' | 'within_tolerance' | 'manual_review' | 'overridden';
 /** Endpoint to fetch the full match snapshot; only set when the bill has a PO. */
 three_way_match_url?: string | null;
}

export interface CreateBillItemData {
 expense_account_id: string;
 /** REC-02 — PO item FK so the backend can align bill lines to PO lines. */
 item_id?: string;
 description: string;
 quantity: string;
 unit?: string;
 unit_price: string;
}
export interface CreateBillData {
 bill_number: string;
 vendor_id: string;
 /** REC-02 — links the bill to a PO to trigger 3-way match. */
 purchase_order_id?: string;
 /** REC-02 — post despite blocking variances (audit-trailed). */
 allow_override?: boolean;
 override_reason?: string;
 date: string;
 due_date?: string;
 is_vatable?: boolean;
 remarks?: string;
 items: CreateBillItemData[];
}
export interface CreateBillPaymentData {
 cash_account_id: string;
 payment_date: string;
 amount: string;
 payment_method: PaymentMethod;
 reference_number?: string;
}

export interface Customer {
 id: string;
 name: string;
 code: string | null;
 contact_person: string | null;
 email: string | null;
 phone: string | null;
 address: string | null;
 tin: string | null;
 credit_limit: string | null;
 credit_used: string | null;
 credit_available: string | null;
 credit_warning_ratio?: number;
 credit_warning?: boolean;
 payment_terms_days: number;
 is_active: boolean;
 invoices_count?: number;
}

export interface CreateCustomerData {
 name: string;
 code?: string | null;
 contact_person?: string;
 email?: string;
 phone?: string;
 address?: string;
 tin?: string;
 credit_limit?: string | null;
 payment_terms_days?: number;
 is_active?: boolean;
}
export type UpdateCustomerData = Partial<CreateCustomerData>;

export type InvoiceStatus = 'draft' | 'finalized' | 'partial' | 'paid' | 'cancelled';

export interface InvoiceItem {
 id: number;
 description: string;
 quantity: string;
 unit: string | null;
 unit_price: string;
 total: string;
 revenue_account?: { id: string; code: string; name: string } | null;
}
export interface Collection {
 id: string;
 collection_date: string;
 amount: string;
 payment_method: PaymentMethod;
 payment_method_label?: string;
 reference_number: string | null;
 cash_account?: { id: string; code: string; name: string } | null;
 journal_entry_id: string | null;
 created_at?: string;
} export interface Invoice {
 /** 2026-08-08 — O2C stepper: upstream sales order + delivery. */
 sales_order?: { id: string; so_number: string } | null;
 delivery?: { id: string; delivery_number: string } | null;
 id: string;
 invoice_number: string | null;
 date: string;
 due_date: string;
 is_vatable: boolean;
 subtotal: string;
 vat_amount: string;
 total_amount: string;
 amount_paid: string;
 balance: string;
 status: InvoiceStatus;
 display_status: string;
 is_overdue: boolean;
 aging_bucket: string;
 remarks: string | null;
 customer?: { id: string; name: string } | null;
 items?: InvoiceItem[];
 collections?: Collection[];
 journal_entry?: { id: string; entry_number: string; status: JournalEntryStatus; status_label?: string } | null;
}

export interface CreateInvoiceItemData {
 revenue_account_id: string;
 description: string;
 quantity: string;
 unit?: string;
 unit_price: string;
}
export interface CreateInvoiceData {
 customer_id: string;
 date: string;
 due_date?: string;
 is_vatable?: boolean;
 remarks?: string;
 items: CreateInvoiceItemData[];
}
export interface CreateCollectionData {
 cash_account_id: string;
 collection_date: string;
 amount: string;
 payment_method: PaymentMethod;
 reference_number?: string;
}

// ─── Statements ───────────────────────────────
export interface TrialBalanceRow {
 code: string;
 name: string;
 type: AccountType;
 type_label?: string;
 normal_balance: NormalBalance;
 normal_balance_label?: string;
 debit_total: string;
 credit_total: string;
 balance: string;
 balance_side: string;
}
export interface TrialBalance {
 from: string;
 to: string;
 accounts: TrialBalanceRow[];
 totals: { debit: string; credit: string };
}
export interface IncomeStatement {
 from: string;
 to: string;
 revenue: { accounts: { code: string; name: string; amount: string }[]; total: string };
 cogs: { accounts: { code: string; name: string; amount: string }[]; total: string };
 gross_profit: string;
 operating_expenses: { accounts: { code: string; name: string; amount: string }[]; total: string };
 operating_income: string;
 net_income: string;
}
export interface BalanceSheet {
 as_of: string;
 assets: { accounts: { code: string; name: string; amount: string }[]; total: string };
 liabilities: { accounts: { code: string; name: string; amount: string }[]; total: string };
 equity: { accounts: { code: string; name: string; amount: string }[]; total: string };
 total_assets: string;
 total_liabilities_equity: string;
 balanced: boolean;
}

// ─── AR / AP Aging (REC-15) ───────────────────
export interface ArAgingRow extends AgingBuckets {
 customer_id: string;
 customer_name: string;
}
export interface ApAgingRow extends AgingBuckets {
 vendor_id: string;
 vendor_name: string;
}
export interface ArAging {
 buckets: AgingBuckets;
 by_customer: ArAgingRow[];
}
export interface ApAging {
 buckets: AgingBuckets;
 by_vendor: ApAgingRow[];
}

// ─── Dashboard ────────────────────────────────
export interface AgingBuckets {
 current: string;
 d1_30: string;
 d31_60: string;
 d61_90: string;
 d91_plus: string;
 total: string;
}
export interface FinanceDashboardSummary {
 cash_balance: string;
 ar_outstanding: string;
 ap_outstanding: string;
 revenue_mtd: string;
 ar_aging_summary: AgingBuckets;
 ap_aging_summary: AgingBuckets;
 recent_journal_entries: Array<{
 id: string; entry_number: string; date: string; description: string; total_debit: string; reference: string | null;
 }>;
 top_overdue_customers: Array<{
 customer_id: string; customer_name: string;
 current: string; d1_30: string; d31_60: string; d61_90: string; d91_plus: string; total: string;
 }>;
 // Task D5 — Finance Officer dashboard extensions. All optional so older
 // server payloads (and tests with fewer fixtures) keep type-checking.
 payroll_pipeline?: {
 draft: number; processing: number; approved: number;
 finalized: number; disbursed: number; total: number;
 stages?: Array<{ value: string; label: string; count: number }>;
 };
 unposted_jes?: { count: number; oldest_date: string | null };
 ap_due_this_week?: {
 count: number; total: string;
 items: Array<{ id: string; bill_number: string; vendor_name: string; due_date: string; balance: string }>;
 };
 ap_due_horizon_days?: number;
 payroll_pipeline_history_days?: number;
 budget_vs_actual_top?: Array<{
 category: string; budget: string | null; actual: string | null; variance: string | null; variance_pct: number | null;
 }> | null;
 revenue_forecast?: import('./forecasting-dashboard').ForecastPanelData;
}

// ─── REC-13 — Credit notes (AR/AP) ─────────────────────────
export type CreditNoteType = 'customer' | 'supplier';
export type CreditNoteStatus = 'draft' | 'finalized' | 'applied' | 'void';

export interface CreditNoteLine {
 id: string | null;
 description: string;
 amount: string;
}
export interface CreditNoteApplicationRow {
 id: string;
 invoice_id: string | null;
 bill_id: string | null;
 amount: string;
 created_at: string | null;
}
export interface CreditNote {
 id: string;
 credit_note_number: string | null;
 type: CreditNoteType;
 type_label: string;
 status: CreditNoteStatus;
 status_label: string;
 date: string;
 is_vatable: boolean;
 subtotal: string;
 vat_amount: string;
 total_amount: string;
 applied_amount: string;
 balance: string;
 reason: string | null;
 customer?: { id: string; name: string } | null;
 vendor?: { id: string; name: string } | null;
 invoice?: { id: string; invoice_number: string } | null;
 bill?: { id: string; bill_number: string } | null;
 lines?: CreditNoteLine[];
 applications?: CreditNoteApplicationRow[];
 created_at?: string;
}
export interface CreateCreditNoteData {
 type: CreditNoteType;
 date: string;
 is_vatable?: boolean;
 reason?: string;
 customer_id?: string;
 vendor_id?: string;
 invoice_id?: string;
 bill_id?: string;
 lines: Array<{ account_id: string; description: string; amount: string }>;
}
export interface ApplyCreditNoteData {
 amount: string;
 invoice_id?: string;
 bill_id?: string;
}
