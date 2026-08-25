import { client } from '../client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type { Budget, BudgetOverview, BudgetVsActual, BudgetCheckAvailability, BudgetSyncRun, FiscalYear } from '@/types/budgeting';

export interface BudgetListParams {
 fiscal_year_id?: string;
 department_id?: string;
 company_wide?: boolean;
 status?: string;
 per_page?: number;
 page?: number;
}

export interface CreateBudgetData {
 fiscal_year_id: string;
 department_id?: string | null;
 budget_type: string;
 name: string;
 line_items: Array<{
 account_id: string;
 jan?: string;
 feb?: string;
 mar?: string;
 apr?: string;
 may?: string;
 jun?: string;
 jul?: string;
 aug?: string;
 sep?: string;
 oct?: string;
 nov?: string;
 dec?: string;
 }>;
}

export type UpdateBudgetData = Partial<Omit<CreateBudgetData, 'line_items'>> & {
 line_items?: CreateBudgetData['line_items'];
};

export interface CreateTransferData {
 from_budget_line_id: string;
 to_budget_line_id: string;
 amount: string;
 reason: string;
}

export const budgetingApi = {
 options: () => client.get<{ data: { budget_types: Array<{ value: string; label: string }>; statuses: Array<{ value: string; label: string }>; warning_ratio_pct: number; critical_ratio_pct: number; exhausted_ratio_pct: number } }>('/budgets/options').then((r) => r.data.data),
 // Fiscal Years
 fiscalYears: () =>
 client.get<{ data: FiscalYear[] }>('/budgets/fiscal-years').then((r) => r.data.data),

 // Budgets
 list: (params?: BudgetListParams) =>
 client.get<PaginatedResponse<Budget>>('/budgets', { params }).then((r) => r.data),

 show: (id: string) =>
 client.get<ApiSuccess<Budget>>(`/budgets/${id}`).then((r) => r.data.data),

 create: (data: CreateBudgetData) =>
 client.post<ApiSuccess<Budget>>('/budgets', data).then((r) => r.data.data),

 update: (id: string, data: UpdateBudgetData) =>
 client.put<ApiSuccess<Budget>>(`/budgets/${id}`, data).then((r) => r.data.data),

 submit: (id: string) =>
 client.post(`/budgets/${id}/submit`).then((r) => r.data),

 approve: (id: string) =>
 client.post(`/budgets/${id}/approve`).then((r) => r.data),

 close: (id: string) =>
 client.post(`/budgets/${id}/close`).then((r) => r.data),

 // Overview & Reports
 overview: (fiscalYearId?: string) =>
 client.get<{ data: BudgetOverview }>('/budgets/overview', { params: { fiscal_year_id: fiscalYearId } }).then((r) => r.data.data),

 budgetVsActual: (fiscalYearId?: string) =>
 client.get<{ data: BudgetVsActual }>('/budgets/budget-vs-actual', { params: { fiscal_year_id: fiscalYearId } }).then((r) => r.data.data),

 syncActuals: (fiscalYearId?: string) =>
 client.post<{ data: { dispatched: boolean; outbox_id: string; status: string; run_id: string | null; fiscal_year_id: string | null; run_status: BudgetSyncRun['status'] | null } }>('/budgets/sync-actuals', {
 fiscal_year_id: fiscalYearId,
 }).then((r) => r.data.data),

 syncStatus: (fiscalYearId?: string) =>
 client.get<{ data: BudgetSyncRun | null }>('/budgets/sync-actuals/status', { params: { fiscal_year_id: fiscalYearId } }).then((r) => r.data.data),

 // Budget Enforcement
 checkAvailability: (departmentId: string, amount: string, fiscalYearId?: string) =>
 client.get<{ data: BudgetCheckAvailability }>('/budgets/check-availability', {
 params: { department_id: departmentId, amount, fiscal_year_id: fiscalYearId },
 }).then((r) => r.data.data),

};
