export interface FiscalYear {
  id: string;
  year: number;
  start_date: string;
  end_date: string;
  status: 'draft' | 'active' | 'closed';
}

export interface Budget {
  id: string;
  fiscal_year_id: number;
  fiscal_year?: FiscalYear;
  department_id?: number | null;
  department?: { id: string; name: string; code: string } | null;
  budget_type: string;
  name: string;
  total_allocated: number;
  total_spent: number;
  total_committed: number;
  available: number;
  utilization_pct: number;
  status: 'draft' | 'submitted' | 'approved' | 'active' | 'closed';
  submitted_by?: { id: string; name: string } | null;
  submitted_at?: string | null;
  approved_by?: { id: string; name: string } | null;
  approved_at?: string | null;
  line_items?: BudgetLineItem[];
  created_at: string;
  updated_at: string;
}

export interface BudgetLineItem {
  id: string;
  budget_id: number;
  account_id: number;
  account?: { id: string; code: string; name: string };
  jan: number;
  feb: number;
  mar: number;
  apr: number;
  may: number;
  jun: number;
  jul: number;
  aug: number;
  sep: number;
  oct: number;
  nov: number;
  dec: number;
  annual_total: number;
  actual_total: number;
  variance: number;
}

export interface BudgetTransfer {
  id: string;
  from_budget_line_id: number;
  to_budget_line_id: number;
  from_line_item?: BudgetLineItem;
  to_line_item?: BudgetLineItem;
  amount: number;
  reason: string;
  status: 'pending' | 'approved' | 'rejected';
  requested_by?: { id: string; name: string } | null;
  approved_by?: { id: string; name: string } | null;
  approved_at?: string | null;
  created_at: string;
}

export interface BudgetOverview {
  total_allocated: number;
  total_spent: number;
  total_committed: number;
  total_available: number;
  utilization_pct: number;
  by_department: BudgetOverviewDepartment[];
}

export interface BudgetOverviewDepartment {
  department: string;
  allocated: number;
  spent: number;
  committed: number;
  available: number;
  pct: number;
}

export interface BudgetVsActualRow {
  budget_id: string;
  account_code: string;
  account_name: string;
  budget_type: string;
  department: string;
  budgeted: number;
  actual: number;
  variance: number;
  variance_pct: number;
}

export interface BudgetVsActual {
  rows: BudgetVsActualRow[];
  total_budgeted: number;
  total_actual: number;
  total_variance: number;
}

export interface BudgetCheckAvailability {
  can_proceed: boolean;
  level: 'ok' | 'warning' | 'critical' | 'exhausted' | 'overdrawn';
  message: string;
}
