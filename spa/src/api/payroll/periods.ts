import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreatePayrollPeriodData, PayrollPeriod } from '@/types/payroll';

export interface PeriodListParams extends ListParams {
  status?: string;
  year?: number | string;
  is_first_half?: boolean | string;
  is_thirteenth_month?: boolean | string;
}

export const periodsApi = {
  list: (params?: PeriodListParams) =>
    client.get<PaginatedResponse<PayrollPeriod>>('/payroll-periods', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}`).then((r) => r.data.data),
  create: (data: CreatePayrollPeriodData) =>
    client.post<ApiSuccess<PayrollPeriod>>('/payroll-periods', data).then((r) => r.data.data),
  compute: (id: string) =>
    client.post<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/compute`).then((r) => r.data.data),
  approve: (id: string) =>
    client.patch<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/approve`).then((r) => r.data.data),
  finalize: (id: string) =>
    client.patch<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/finalize`).then((r) => r.data.data),
  bankFileUrl: (id: string) => `/api/v1/payroll-periods/${id}/bank-file`,
  runThirteenthMonth: (year: number, payroll_date?: string) =>
    client
      .post<ApiSuccess<PayrollPeriod>>('/payroll-periods/thirteenth-month', { year, payroll_date })
      .then((r) => r.data.data),
};
