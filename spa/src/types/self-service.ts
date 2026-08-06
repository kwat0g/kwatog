// ─── Self-service portal (Task U3) ────────────────────────────────
export interface SelfServiceLeaveBalance {
 code: string;
 name: string;
 total: number;
 used: number;
 remaining: number;
}

export interface SelfServiceShift {
 name: string;
 time_in: string;
 time_out: string;
}

export interface SelfServicePayslipSummary {
 id: string;
 period_start: string;
 period_end: string;
 gross_pay: string;
 net_pay: string;
}

export interface SelfServiceHome {
 greeting: string;
 today: string;
 employee: {
 id: string;
 employee_no: string;
 first_name: string;
 full_name: string;
 department: string | null;
 position: string | null;
 };
 todays_shift: SelfServiceShift | null;
 leave_balances: SelfServiceLeaveBalance[];
 leave_balance_policy?: { warning_ratio: number; critical_ratio: number };
 pending_count: number;
 latest_payslip: SelfServicePayslipSummary | null;
}

export interface SelfServiceLoan {
 id: string;
 loan_type: string | null;
 loan_type_label?: string | null;
 principal: string | null;
 outstanding_balance: string | null;
 monthly_amortization: string | null;
 periods: number;
 periods_remaining: number;
 status: string;
 status_label?: string;
 created_at: string | null;
}

export interface SelfServiceLoansResponse {
 active: SelfServiceLoan[];
 history: SelfServiceLoan[];
 loan_types: Array<{ value: string; label: string; interest_rate: string; approval_steps: number }>;
 max_pay_periods: number;
}

export interface SelfServiceProfile {
 id: string;
 employee_no: string;
 full_name: string;
 first_name: string;
 middle_name: string | null;
 last_name: string;
 birth_date: string | null;
 nationality: string | null;
 gender: string | null;
 gender_label?: string | null;
 civil_status: string | null;
 civil_status_label?: string | null;
 department: string | null;
 position: string | null;
 date_hired: string | null;
 date_regularized: string | null;
 expected_regularization_date?: string | null;
 employment_type: string | null;
 employment_type_label?: string | null;
 pay_type?: string | null;
 pay_type_label?: string | null;
 status?: string | null;
 status_label?: string | null;
 photo_path: string | null;
 /** Authenticated endpoint — requires session cookie; use in <img src>. */
 photo_url: string | null;
 mobile_number: string | null;
 email: string | null;
 street_address: string | null;
 barangay: string | null;
 city: string | null;
 province: string | null;
 zip_code: string | null;
 emergency_contact_name: string | null;
 emergency_contact_relation: string | null;
 emergency_contact_phone: string | null;
 bank_name: string | null;
 bank_account_last4: string | null;
 sss_no_last4: string | null;
 philhealth_no_last4: string | null;
 pagibig_no_last4: string | null;
 tin_last4: string | null;
 profile_completeness?: { percent: number; missing_fields: string[] };
}

export interface ProfileUpdateRequestRecord {
 id: string;
 // pending_finance: HR approved, awaiting Finance (bank-account changes only).
 status: 'pending' | 'pending_finance' | 'approved' | 'rejected';
 status_label?: string;
 changes: Record<string, string | null>;
 note: string | null;
 reviewed_at: string | null;
 created_at: string | null;
}

// ─── Overtime (Task SS1) ──────────────────────────────────────────
export type OvertimeStatus = 'pending' | 'approved' | 'rejected';

export interface SelfServiceOvertimeRequest {
 id: string;
 date: string | null;
 hours_requested: string;
 reason: string | null;
 status: OvertimeStatus | null;
 status_label?: string | null;
 rejection_reason: string | null;
 approver: string | null;
 created_at: string | null;
}

export interface SelfServiceOvertimeResponse {
 pending: SelfServiceOvertimeRequest[];
 history: SelfServiceOvertimeRequest[];
 todays_shift: SelfServiceShift | null;
 /** Estimated hourly rate for the OT pay preview (display-only). */
 hourly_rate: string | null;
 minimum_hours: number;
 maximum_hours: number;
 premium_multiplier: number;
}

export interface ApplyOvertimePayload {
 date: string;
 hours_requested: number;
 reason: string;
}

// ─── Documents (Task SS3) ─────────────────────────────────────────
export interface SelfServiceCertificate {
 key: 'employment' | 'sss' | 'philhealth' | 'pagibig' | 'bir_2316';
 label: string;
 available: boolean;
 note: string;
}

export interface SelfServiceDocumentsResponse {
 certificates: SelfServiceCertificate[];
 current_year: number;
 bir_2316_year: number;
}

// ─── Leave filing (Task SS-LF) ─────────────────────────────────────
export interface SelfServiceLeaveType {
 id: string;
 code: string;
 name: string;
 requires_document: boolean;
}

export interface SelfServiceLeaveBalanceSelf {
 id: string;
 leave_type: {
 id: string;
 code: string;
 name: string;
 };
 year: number;
 total_credits: string;
 used: string;
 remaining: string;
}

export interface FileLeavePayload {
 employee_id: string;
 leave_type_id: string;
 start_date: string;
 end_date: string;
 /** M-18 — 'am' | 'pm' for half-day; omit for full-day. */
 half_day_period?: 'am' | 'pm';
 reason?: string;
}

// ─── Loan amortization preview (Task SS-LP) ───────────────────────
export interface LoanAmortizationRow {
 period: number;
 amount: string;
 running_balance: string;
}

export interface LoanAmortizationPreview {
 monthly_amortization: string;
 schedule: LoanAmortizationRow[];
}
