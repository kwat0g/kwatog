import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Shift, CreateShiftData, UpdateShiftData, BulkAssignShiftData } from '@/types/attendance';

export const shiftsApi = {
  list: (params?: ListParams) =>
    client.get<PaginatedResponse<Shift>>('/attendance/shifts', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Shift>>(`/attendance/shifts/${id}`).then((r) => r.data.data),
  create: (data: CreateShiftData) =>
    client.post<ApiSuccess<Shift>>('/attendance/shifts', data).then((r) => r.data.data),
  update: (id: string, data: UpdateShiftData) =>
    client.put<ApiSuccess<Shift>>(`/attendance/shifts/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/attendance/shifts/${id}`),
  bulkAssign: (data: BulkAssignShiftData) =>
    client.post<{ data: { count: number; shift_id: number; department_id: number } }>(
      '/attendance/shifts/bulk-assign', data,
    ).then((r) => r.data.data),
};
