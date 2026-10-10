import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Shift, CreateShiftData, UpdateShiftData, BulkAssignShiftData, EmployeeShiftAssignment } from '@/types/attendance';

/** The employee's active shift assignment: its id, schedule (null = default Mon–Sat) and start date. */
export interface CurrentShiftAssignment {
 id: string;
 working_days: number[] | null;
 effective_date: string;
}

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
  restore: (id: string) => client.patch(`/attendance/shifts/${id}/restore`),
  bulkAssign: (data: BulkAssignShiftData) =>
 client.post<{ data: { count: number; shift_id: number; department_id: number } }>(
 '/attendance/shifts/bulk-assign', data,
 ).then((r) => r.data.data),
 assignEmployee: (employeeId: string, data: { shift_id: string; effective_date: string; end_date?: string | null; working_days: number[] }) =>
 client.post(`/attendance/shifts/assign-employee/${employeeId}`, data).then((r) => r.data),
 assignments: (departmentId?: string, page = 1) =>
 client.get<PaginatedResponse<EmployeeShiftAssignment>>('/attendance/shift-assignments', { params: { department_id: departmentId || undefined, page } }).then((r) => r.data),
 configureWorkingDays: (id: string, workingDays: number[]) =>
 client.patch<ApiSuccess<EmployeeShiftAssignment>>(`/attendance/shift-assignments/${id}/working-days`, { working_days: workingDays }).then((r) => r.data.data),
 currentAssignment: (employeeId: string) =>
 client
 .get<{ data: Shift | null; assignment: CurrentShiftAssignment | null }>(`/attendance/shifts/current/${employeeId}`)
 .then((r) => r.data),
 currentForEmployee: (employeeId: string) =>
 client.get<{ data: Shift | null }>(`/attendance/shifts/current/${employeeId}`).then((r) => r.data.data),
};
