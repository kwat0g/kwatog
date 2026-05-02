import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { OvertimeRequest } from '@/types/attendance';

export interface OvertimeListParams extends ListParams {
  employee_id?: string;
  status?: string;
  from?: string;
  to?: string;
}

export interface CreateOvertimeData {
  employee_id: string;
  date: string;
  hours_requested: number;
  reason: string;
}

export const overtimeApi = {
  list: (params?: OvertimeListParams) =>
    client.get<PaginatedResponse<OvertimeRequest>>('/attendance/overtime-requests', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<OvertimeRequest>>(`/attendance/overtime-requests/${id}`).then((r) => r.data.data),
  create: (data: CreateOvertimeData) =>
    client.post<ApiSuccess<OvertimeRequest>>('/attendance/overtime-requests', data).then((r) => r.data.data),
  approve: (id: string, remarks?: string) =>
    client.patch<ApiSuccess<OvertimeRequest>>(`/attendance/overtime-requests/${id}/approve`, { remarks })
      .then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client.patch<ApiSuccess<OvertimeRequest>>(`/attendance/overtime-requests/${id}/reject`, { reason })
      .then((r) => r.data.data),
};
