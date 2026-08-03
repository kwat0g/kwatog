// 'computed' = the batch ran and rows exist, awaiting a checker's approval.
// 'draft' now strictly means "never computed" — the two used to share 'draft',
// which is why the Compute button never disabled after a successful run.
export type PayrollPeriodStatus = 'draft' | 'processing' | 'computed' | 'approved' | 'finalized' | 'disbursed' | 'voided';
export type DisbursementStatus = 'pending' | 'partially_disbursed' | 'disbursed';
export type ProofType = 'deposit_slip' | 'bank_confirmation' | 'transfer_receipt' | 'other';
export type PayrollAdjustmentType = 'underpayment' | 'overpayment';
export type PayrollAdjustmentStatusValue = 'pending' | 'approved' | 'rejected' | 'applied';
export type DeductionTypeValue =
  | 'sss' | 'philhealth' | 'pagibig' | 'withholding_tax'
  | 'loan' | 'cash_advance' | 'adjustment' | 'thirteenth_month' | 'other';

export type ContributionAgency = 'sss' | 'philhealth' | 'pagibig' | 'bir';

export interface GovernmentTable {
  id: string;
  agency: ContributionAgency;
  agency_label: string;
  bracket_min: string;
  bracket_max: string;
  ee_amount: string;
  er_amount: string;
  effective_date: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface PayrollPeriodSummary {
  employee_count: number;
  failed_count: number;
  total_gross: string;
  total_deductions: string;
  total_net: string;
}

export interface BankFileRef {
  id: string;
  record_count: number;
  total_amount: string;
  generated_at: string | null;
  generator: { id: string; name: string } | null;
}

export interface PayrollPeriod {
  id: string;
  period_start: string;
  period_end: string;
  payroll_date: string;
  is_first_half: boolean;
  is_thirteenth_month: boolean;
  status: PayrollPeriodStatus;
  status_label: string;
  lifecycle_steps?: Array<{ key: string; label: string; state: 'pending' | 'active' | 'done' }>;
  is_locked: boolean;
  label: string;
  /** Scope — false when this run is limited to a slice of the workforce. */
  is_company_wide?: boolean;
  scope_label?: string | null;
  scope_employment_types?: string[];
  scope_pay_types?: string[];
  scope_departments?: Array<{ id: string; name: string }>;
  is_auto_created: boolean;
  auto_created_at: string | null;
  employee_count: number;
  creator?: { id: string; name: string };
  summary?: PayrollPeriodSummary | null;
  gl_entry_number?: string | null;
  bank_files?: BankFileRef[];
  adjustment_counts?: {
    pending: number;
    approved: number;
    applied: number;
    rejected: number;
  };
  /** ADV1 — Salary disbursement proof files attached to this period. */
  disbursement_proofs?: DisbursementProof[];
  disbursement_status?: DisbursementStatus;
  disbursed_at?: string | null;
  disburser?: { id: string; name: string } | null;
  /** REC-01 — void audit trail (set once a finalized period is voided). */
  voided_at?: string | null;
  void_reason?: string | null;
  voider?: { id: string; name: string } | null;
  /** REC-04 — maker-checker attribution: who computed / approved / finalized. */
  approved_at?: string | null;
  finalized_at?: string | null;
  computer?: { id: string; name: string } | null;
  approver?: { id: string; name: string } | null;
  finalizer?: { id: string; name: string } | null;
  /** When the current compute run claimed this period (null when not running). */
  processing_started_at?: string | null;
  /**
   * True when a Processing claim is older than the stale threshold — its worker
   * is presumed dead (OOM/restart). The next Compute click takes the claim over,
   * and the hourly payroll:reap-stale-runs sweep releases it either way.
   */
  is_compute_stale?: boolean;
  /** Last progress snapshot of an in-flight run. Null unless Processing. */
  compute_progress?: PayrollComputeProgress | null;
  created_at: string;
  updated_at: string;
}

/**
 * Live compute-run progress. Arrives two ways: pushed over Reverb as
 * `.payroll.progress` on `payroll.period.{id}`, and cached server-side so a
 * page loaded mid-run renders a real bar on first paint.
 */
export interface PayrollComputeProgress {
  processed: number;
  total: number;
  failures: number;
  percent: number;
  updated_at: string;
}

/** Broadcast payload — same shape plus the period's status at emit time. */
export interface PayrollProgressEventPayload extends PayrollComputeProgress {
  period_id: string;
  status?: PayrollPeriodStatus | null;
}

/**
 * ADV1 — One proof file (deposit slip / bank confirmation) attached to
 * a payroll period after salaries have been disbursed.
 */
export interface DisbursementProof {
  id: string;
  proof_type: ProofType;
  proof_type_label?: string;
  file_name: string;
  bank_name: string | null;
  transaction_reference: string | null;
  disbursed_amount: string | null;
  disbursement_date: string;
  notes: string | null;
  uploader: { id: string; name: string } | null;
  created_at: string;
}

export type PayrollAnomalyType =
  | 'large_change'
  | 'excessive_ot'
  | 'high_deduction'
  | 'first_payroll'
  | 'zero_pay';

export interface PayrollAnomalyFlag {
  id: string;
  flag_type: PayrollAnomalyType;
  details: Record<string, unknown>;
  is_resolved: boolean;
  resolution_remarks: string | null;
  resolved_at: string | null;
  resolved_by?: { id: string; name: string } | null;
  employee?: { id: string; employee_no: string; name: string } | null;
  payroll_id: string | null;
  created_at: string;
}

export interface PayrollEmployeeRef {
  id: string;
  employee_no: string;
  full_name: string;
  department?: string | null;
  position?: string | null;
}

export interface PayrollDeductionDetail {
  deduction_type: DeductionTypeValue;
  deduction_type_label: string;
  description: string | null;
  amount: string;
  reference_id: number | null;
}

export interface Payroll {
  id: string;
  period_id?: string;
  employee?: PayrollEmployeeRef | null;
  pay_type: string;
  days_worked: string | null;
  basic_pay: string;
  leave_pay: string;
  overtime_pay: string;
  night_diff_pay: string;
  holiday_pay: string;
  gross_pay: string;
  sss_ee: string;
  sss_er: string;
  philhealth_ee: string;
  philhealth_er: string;
  pagibig_ee: string;
  pagibig_er: string;
  withholding_tax: string;
  loan_deductions: string;
  other_deductions: string;
  adjustment_amount: string;
  total_deductions: string;
  net_pay: string;
  error_message: string | null;
  computed_at: string | null;
  /** ADV1 — Disbursement status from the parent period. */
  period_disbursement_status?: 'pending' | 'partially_disbursed' | 'disbursed';
  deduction_details?: PayrollDeductionDetail[];
  created_at: string;
  updated_at: string;
}

export interface PayrollAdjustment {
  id: string;
  period?: { id: string; label: string };
  employee?: { id: string; employee_no: string; full_name: string };
  original_payroll_id?: string;
  type: PayrollAdjustmentType;
  type_label: string;
  amount: string;
  reason: string;
  status: PayrollAdjustmentStatusValue;
  status_label: string;
  approver?: { id: string; name: string } | null;
  applied_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface CreatePayrollPeriodData {
  period_start: string;
  period_end: string;
  payroll_date: string;
  /**
   * Derived server-side from period_start (day 1-15 = first half), NOT chosen.
   * A period whose label contradicted its dates produced an inverted pay-cycle
   * key, which let one employee be paid twice in a month and moved government
   * contributions onto the wrong cutoff. Optional here only for callers that
   * still send it; the server ignores it for normal cutoffs.
   */
  is_first_half?: boolean;
  is_thirteenth_month?: boolean;
  /**
   * Scope filters. All optional and ANDed together; omitting every one means
   * the run pays the whole company (the default and historical behaviour).
   */
  scope_employment_types?: string[];
  scope_pay_types?: string[];
  /** Department hash ids. */
  scope_department_ids?: string[];
  scope_label?: string;
}

/** Dry-run of a scope: who it would pay, and who is already paid this cutoff. */
export interface PayrollScopePreview {
  is_company_wide: boolean;
  employee_count: number;
  already_paid_count: number;
  already_paid_sample: Array<{ employee_no: string; name: string; period: string }>;
  total_active: number;
  estimated_gross: string;
}

export interface CreatePayrollAdjustmentData {
  original_payroll_id: string;
  type: PayrollAdjustmentType;
  amount: string;
  reason: string;
}

// ─── CA3 — Payroll pipeline ─────────────────────────────────────────

export interface PipelinePeriod {
  id: string | null;
  period_start: string;
  period_end: string;
  is_first_half: boolean;
  status: PayrollPeriodStatus | 'scheduled' | 'not_created';
  status_label: string;
  is_auto_created: boolean;
  employee_count: number;
  total_gross: string;
  total_net: string;
  label: string;
  exists: boolean;
}

export interface PayrollPipeline {
  year: number;
  periods: PipelinePeriod[];
  auto_schedule_enabled: boolean;
  next_auto_run: string | null;
}

// Task 9 — Period-over-period variance report
export interface PayrollVarianceSummary {
  employee_count: number;
  failed_count: number;
  total_gross: string;
  total_deductions: string;
  total_net: string;
  period_label: string;
}

export interface PayrollVarianceReport {
  current: PayrollVarianceSummary;
  previous: PayrollVarianceSummary;
  delta: {
    gross: number;
    net: number;
    deductions: number;
    headcount: number;
  };
  pct_change: {
    gross: number | null;
    net: number | null;
    deductions: number | null;
    headcount: number | null;
  };
}

// CA3 — Payroll pipeline
export interface PipelinePeriod {
  id: string | null;
  period_start: string;
  period_end: string;
  is_first_half: boolean;
  status: PayrollPeriodStatus | 'scheduled' | 'not_created';
  status_label: string;
  is_auto_created: boolean;
  employee_count: number;
  total_gross: string;
  total_net: string;
  label: string;
  exists: boolean;
}

export interface PayrollPipeline {
  year: number;
  periods: PipelinePeriod[];
  auto_schedule_enabled: boolean;
  next_auto_run: string | null;
}
