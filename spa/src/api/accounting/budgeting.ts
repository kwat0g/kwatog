import { client } from '../client';
import type { ApiSuccess, PaginatedResponse } from '@/types';
import type { Budget, BudgetTransfer, BudgetOverview, BudgetVsActual, BudgetCheckAvailability, FiscalYear } from '@/types/budgeting';

export interface BudgetListParams {
  fiscal_year_id?: number;
  department_id?: number;
  status?: string;
  per_page?: number;
  page?: number;
}

export interface CreateBudgetData {
  fiscal_year_id: number;
  department_id?: number | null;
  budget_type: string;
  name: string;
  line_items: Array<{
    account_id: number;
    jan?: number;
    feb?: number;
    mar?: number;
    apr?: number;
    may?: number;
    jun?: number;
    jul?: number;
    aug?: number;
    sep?: number;
    oct?: number;
    nov?: number;
    dec?: number;
  }>;
}

export interface CreateTransferData {
  from_budget_line_id: number;
  to_budget_line_id: number;
  amount: number;
  reason: string;
}

export const budgetingApi = {
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

  update: (id: string, data: Partial<Pick<Budget, 'budget_type' | 'name'>>) =>
    client.put<ApiSuccess<Budget>>(`/budgets/${id}`, data).then((r) => r.data.data),

  submit: (id: string) =>
    client.post(`/budgets/${id}/submit`).then((r) => r.data),

  approve: (id: string) =>
    client.post(`/budgets/${id}/approve`).then((r) => r.data),

  close: (id: string) =>
    client.post(`/budgets/${id}/close`).then((r) => r.data),

  // Overview & Reports
  overview: (fiscalYearId?: number) =>
    client.get<{ data: BudgetOverview }>('/budgets/overview', { params: { fiscal_year_id: fiscalYearId } }).then((r) => r.data.data),

  budgetVsActual: (fiscalYearId?: number) =>
    client.get<{ data: BudgetVsActual }>('/budgets/budget-vs-actual', { params: { fiscal_year_id: fiscalYearId } }).then((r) => r.data.data),

  // Budget Enforcement
  checkAvailability: (departmentId: number, amount: number, fiscalYearId?: number) =>
    client.get<{ data: BudgetCheckAvailability }>('/budgets/check-availability', {
      params: { department_id: departmentId, amount, fiscal_year_id: fiscalYearId },
    }).then((r) => r.data.data),

  // Transfers
  transfers: {
    list: (params?: { status?: string; per_page?: number; page?: number }) =>
      client.get<PaginatedResponse<BudgetTransfer>>('/budget-transfers', { params }).then((r) => r.data),

    show: (id: string) =>
      client.get<ApiSuccess<BudgetTransfer>>(`/budget-transfers/${id}`).then((r) => r.data.data),

    create: (data: CreateTransferData) =>
      client.post<ApiSuccess<BudgetTransfer>>('/budget-transfers', data).then((r) => r.data.data),

    approve: (id: string) =>
      client.post(`/budget-transfers/${id}/approve`).then((r) => r.data),

    reject: (id: string) =>
      client.post(`/budget-transfers/${id}/reject`).then((r) => r.data),
  },
};
