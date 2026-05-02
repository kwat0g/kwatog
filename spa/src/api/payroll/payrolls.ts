import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Payroll } from '@/types/payroll';

export interface PayrollListParams extends ListParams {
  period_id?: string;
  employee_id?: string;
  failed_only?: boolean;
}

export const payrollsApi = {
  list: (params?: PayrollListParams) =>
    client.get<PaginatedResponse<Payroll>>('/payrolls', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Payroll>>(`/payrolls/${id}`).then((r) => r.data.data),
  recompute: (id: string) =>
    client.post<ApiSuccess<Payroll>>(`/payrolls/${id}/recompute`).then((r) => r.data.data),
  payslipUrl: (id: string) => `/api/v1/payrolls/${id}/payslip`,
};
