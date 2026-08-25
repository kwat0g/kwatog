export interface FiscalYear {
 id: string;
 year: number;
 start_date: string;
 end_date: string;
 status: 'draft' | 'active' | 'closed';
 status_label?: string;
}

export interface Budget {
 id: string;
 fiscal_year_id: string;
 fiscal_year?: FiscalYear;
 department_id?: string | null;
 department?: { id: string; name: string; code: string } | null;
 budget_type: string;
 name: string;
 total_allocated: string;
 total_spent: string;
 total_committed: string;
 available: string;
 utilization_pct: number;
 status: 'draft' | 'submitted' | 'approved' | 'active' | 'closed';
 status_label?: string;
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
 budget_id: string | null;
 account_id: string | null;
 account?: { id: string; code: string; name: string };
 jan: string;
 feb: string;
 mar: string;
 apr: string;
 may: string;
 jun: string;
 jul: string;
 aug: string;
 sep: string;
 oct: string;
 nov: string;
 dec: string;
 annual_total: string;
 actual_total: string;
 variance: string;
}


export interface BudgetOverview {
 total_allocated: string;
 total_spent: string;
 total_committed: string;
 total_available: string;
 utilization_pct: number;
 by_department: BudgetOverviewDepartment[];
}

export interface BudgetOverviewDepartment {
 department_id?: string | null;
 department: string;
 allocated: string;
 spent: string;
 committed: string;
 available: string;
 pct: number;
}

export interface BudgetVsActualRow {
 budget_id: string;
 account_code: string;
 account_name: string;
 budget_type: string;
 department: string;
 budgeted: string;
 actual: string;
 variance: string;
 variance_pct: number;
}

export interface BudgetVsActual {
 rows: BudgetVsActualRow[];
 total_budgeted: string;
 total_actual: string;
 total_variance: string;
}

export interface BudgetSyncRun {
 id: string;
 fiscal_year_id: string | null;
 status: 'queued' | 'running' | 'completed' | 'failed';
 processed_lines: number;
 total_lines: number;
 last_error: string | null;
 queued_at: string | null;
 started_at: string | null;
 completed_at: string | null;
 failed_at: string | null;
}

export interface BudgetCheckAvailability {
 can_proceed: boolean;
 level: 'ok' | 'warning' | 'critical' | 'exhausted' | 'overdrawn';
 message: string;
}
