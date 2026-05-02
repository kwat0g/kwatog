import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { EmployeeLoan, CreateLoanData, AmortizationItem, LoanLimits } from '@/types/loans';

export interface LoanListParams extends ListParams {
  employee_id?: string;
  loan_type?: string;
  status?: string;
}

export const loansApi = {
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
  previewAmortization: (principal: number, pay_periods: number) =>
    client.post<{ data: AmortizationItem[] }>('/loans/preview-amortization', { principal, pay_periods })
      .then((r) => r.data.data),
};
