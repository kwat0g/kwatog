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
  pending_count: number;
  latest_payslip: SelfServicePayslipSummary | null;
}

export interface SelfServiceLoan {
  id: string;
  loan_type: string | null;
  principal: string;
  outstanding_balance: string;
  monthly_amortization: string;
  periods: number;
  periods_remaining: number;
  status: string;
  created_at: string;
}

export interface SelfServiceLoansResponse {
  active: SelfServiceLoan[];
  history: SelfServiceLoan[];
}

export interface SelfServiceProfile {
  id: string;
  employee_no: string;
  full_name: string;
  first_name: string;
  middle_name: string | null;
  last_name: string;
  department: string | null;
  position: string | null;
  date_hired: string | null;
  employment_type: string | null;
  photo_path: string | null;
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
  sss_no_last4: string | null;
  philhealth_no_last4: string | null;
  pagibig_no_last4: string | null;
  tin_last4: string | null;
}

export interface ProfileUpdateRequestRecord {
  id: string;
  status: 'pending' | 'approved' | 'rejected';
  changes: Record<string, string | null>;
  note: string | null;
  reviewed_at: string | null;
  created_at: string | null;
}
