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

export type BillStatus = 'unpaid' | 'partial' | 'paid' | 'cancelled';
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
  is_overdue: boolean;
  aging_bucket: string;
  remarks: string | null;
  vendor?: { id: string; name: string } | null;
  items?: BillItem[];
  payments?: BillPayment[];
  journal_entry?: { id: string; entry_number: string; status: JournalEntryStatus } | null;
}

export interface CreateBillItemData {
  expense_account_id: string;
  description: string;
  quantity: string;
  unit?: string;
  unit_price: string;
}
export interface CreateBillData {
  bill_number: string;
  vendor_id: string;
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
  contact_person: string | null;
  email: string | null;
  phone: string | null;
  address: string | null;
  tin: string | null;
  credit_limit: string | null;
  credit_used: string | null;
  credit_available: string | null;
  payment_terms_days: number;
  is_active: boolean;
  invoices_count?: number;
}

export interface CreateCustomerData {
  name: string;
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
  reference_number: string | null;
  cash_account?: { id: string; code: string; name: string } | null;
  journal_entry_id: string | null;
  created_at?: string;
}
export interface Invoice {
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
  journal_entry?: { id: string; entry_number: string; status: JournalEntryStatus } | null;
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
  normal_balance: NormalBalance;
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
}
