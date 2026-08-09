import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { OvertimeRequest, OvertimeOptions } from '@/types/attendance';

export interface OvertimeListParams extends ListParams {
 employee_id?: string;
 status?: string;
 from?: string;
 to?: string;
 search?: string;
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
 options: () =>
 client.get<ApiSuccess<OvertimeOptions>>('/attendance/overtime-requests/options').then((r) => r.data.data),
 create: (data: CreateOvertimeData) =>
 client.post<ApiSuccess<OvertimeRequest>>('/attendance/overtime-requests', data).then((r) => r.data.data),
 approve: (id: string, remarks?: string) =>
 client.patch<ApiSuccess<OvertimeRequest>>(`/attendance/overtime-requests/${id}/approve`, { remarks })
 .then((r) => r.data.data),
 reject: (id: string, reason: string) =>
 client.patch<ApiSuccess<OvertimeRequest>>(`/attendance/overtime-requests/${id}/reject`, { reason })
 .then((r) => r.data.data),
 /** Withdraw a pending request (owner) or cancel any pending one (admin/approver). */
  cancel: (id: string, reason?: string) =>
  client.delete<ApiSuccess<OvertimeRequest>>(`/attendance/overtime-requests/${id}`, {
  data: reason ? { reason } : undefined,
  }).then((r) => r.data.data),
 /** L-23 — bulk approve up to 100 pending requests. Server reports partial successes. */
 bulkApprove: (ids: string[], remarks?: string) =>
 client.post<{
 message: string;
 approved_count: number;
 failed: Array<{ id: number; reason: string }>;
 data: OvertimeRequest[];
 }>('/attendance/overtime-requests/bulk-approve', { ids, remarks })
 .then((r) => r.data),
};
