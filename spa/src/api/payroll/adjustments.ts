import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreatePayrollAdjustmentData, PayrollAdjustment } from '@/types/payroll';

export interface AdjustmentListParams extends ListParams {
  status?: string;
  type?: string;
  employee_id?: string;
}

export const adjustmentsApi = {
  list: (params?: AdjustmentListParams) =>
    client.get<PaginatedResponse<PayrollAdjustment>>('/payroll-adjustments', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PayrollAdjustment>>(`/payroll-adjustments/${id}`).then((r) => r.data.data),
  create: (data: CreatePayrollAdjustmentData) =>
    client.post<ApiSuccess<PayrollAdjustment>>('/payroll-adjustments', data).then((r) => r.data.data),
  approve: (id: string) =>
    client.patch<ApiSuccess<PayrollAdjustment>>(`/payroll-adjustments/${id}/approve`).then((r) => r.data.data),
  reject: (id: string, remarks: string) =>
    client.patch<ApiSuccess<PayrollAdjustment>>(`/payroll-adjustments/${id}/reject`, { remarks }).then((r) => r.data.data),
};
