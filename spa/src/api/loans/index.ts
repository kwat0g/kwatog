import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeLoan, CreateLoanData, AmortizationItem, LoanLimits, LoanType, LoanTypeOption } from '@/types/loans';

export interface LoanListParams extends ListParams {
  employee_id?: string;
  loan_type?: string;
  status?: string;
}

export interface LoanOptions {
  types: LoanTypeOption[];
  statuses: Array<{ value: string; label: string }>;
  approval_sla_hours?: number;
}

export const loansApi = {
  options: () => client.get<ApiSuccess<LoanOptions>>('/loans/options').then((r) => r.data.data),
  types: () => client.get<ApiSuccess<LoanTypeOption[]>>('/loans/types').then((r) => r.data.data),
  list: (params?: LoanListParams) =>
    client.get<PaginatedResponse<EmployeeLoan>>('/loans', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<EmployeeLoan>>(`/loans/${id}`).then((r) => r.data.data),
  create: (data: CreateLoanData) =>
    client.post<ApiSuccess<EmployeeLoan>>('/loans', data).then((r) => r.data.data),
  approve: (id: string, remarks?: string) =>
    client.patch<ApiSuccess<EmployeeLoan>>(`/loans/${id}/approve`, { remarks }).then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client.patch<ApiSuccess<EmployeeLoan>>(`/loans/${id}/reject`, { reason }).then((r) => r.data.data),
  cancel: (id: string) =>
    client.patch<ApiSuccess<EmployeeLoan>>(`/loans/${id}/cancel`).then((r) => r.data.data),
  limits: (employeeId: string, loanType: string) =>
    client.get<{ data: LoanLimits }>(`/loans/limits/${employeeId}`, { params: { loan_type: loanType } })
      .then((r) => r.data.data),
  previewAmortization: (loan_type: LoanType, principal: number, pay_periods: number) =>
    client.post<{ data: AmortizationItem[] }>('/loans/preview-amortization', { loan_type, principal, pay_periods })
      .then((r) => r.data.data),
};
