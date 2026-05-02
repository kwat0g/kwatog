export type LoanType = 'company_loan' | 'cash_advance';
export type LoanStatus = 'pending' | 'active' | 'paid' | 'cancelled' | 'rejected';

export interface LoanPayment {
  id: string;
  amount: string;
  payment_date: string;
  payment_type: string;
  remarks: string | null;
}

export interface EmployeeLoan {
  id: string;
  loan_no: string;
  employee: { id: string; employee_no: string; full_name: string } | null;
  loan_type: LoanType;
  principal: string;
  interest_rate: string;
  monthly_amortization: string;
  total_paid: string;
  balance: string;
  start_date: string | null;
  end_date: string | null;
  pay_periods_total: number;
  pay_periods_remaining: number;
  approval_chain_size: number;
  purpose: string | null;
  status: LoanStatus;
  is_final_pay_deduction: boolean;
  payments?: LoanPayment[];
  created_at: string;
  updated_at: string;
}

export interface CreateLoanData {
  employee_id: string;
  loan_type: LoanType;
  principal: number;
  pay_periods: number;
  purpose?: string;
}

export interface AmortizationItem {
  period: number;
  amount: string;
  remaining_after: string;
}

export interface LoanLimits {
  principal_max: string;
  has_active: boolean;
}
