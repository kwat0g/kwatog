import { client } from '../client';
import type { PaginatedResponse, ApiSuccess } from '@/types';

export type SalaryAdjustmentStatus = 'pending' | 'approved' | 'rejected';

export interface SalaryAdjustmentItem {
  id: string;
  status: SalaryAdjustmentStatus;
  from_basic_monthly_salary: string | null;
  from_daily_rate: string | null;
  to_basic_monthly_salary: string | null;
  to_daily_rate: string | null;
  effective_date: string | null;
  reason: string | null;
  employee: {
    id: string;
    employee_no: string;
    full_name: string;
  } | null;
  requested_by: { id: string; name: string } | null;
  applied_at: string | null;
  created_at: string | null;
}

export interface RequestSalaryAdjustmentData {
  to_basic_monthly_salary?: string | null;
  to_daily_rate?: string | null;
  effective_date: string;
  reason?: string | null;
}

const unwrap = (r: { data: unknown }) =>
  (r.data as { data?: SalaryAdjustmentItem }).data ?? (r.data as SalaryAdjustmentItem);

export const salaryAdjustmentsApi = {
  list: (params?: { status?: SalaryAdjustmentStatus; page?: number; per_page?: number }) =>
    client
      .get<PaginatedResponse<SalaryAdjustmentItem>>('/hr/salary-adjustments', { params })
      .then((r) => r.data),

  request: (employeeId: string, data: RequestSalaryAdjustmentData) =>
    client
      .post<ApiSuccess<SalaryAdjustmentItem>>(`/hr/employees/${employeeId}/salary-adjustments`, data)
      .then(unwrap),

  act: (id: string, action: 'approve' | 'reject', remarks?: string) =>
    client
      .patch<ApiSuccess<SalaryAdjustmentItem>>(`/hr/salary-adjustments/${id}/act`, {
        action,
        remarks: remarks ?? null,
      })
      .then(unwrap),
};
