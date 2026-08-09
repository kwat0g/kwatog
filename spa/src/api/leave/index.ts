import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { LeaveType, LeaveRequest, EmployeeLeaveBalance, CreateLeaveRequestData, LeaveCalendarData } from '@/types/leave';

// Opt-out so transient backend hiccups on these reference queries don't spam
// the global "Something went wrong" toast — the page handles empty state.
const QUIET = { skipErrorToast: true } as const;

export interface CreateLeaveTypeData {
 name: string;
 code: string;
 default_balance: number;
 is_paid?: boolean;
 requires_document?: boolean;
 is_convertible_on_separation?: boolean;
 is_convertible_year_end?: boolean;
 conversion_rate?: number;
 is_active?: boolean;
}

export type UpdateLeaveTypeData = Partial<CreateLeaveTypeData>;

export const leaveTypesApi = {
 list: (params?: { trashed?: string } & Record<string, unknown>) =>
 client.get<PaginatedResponse<LeaveType>>('/leaves/types', { params: { per_page: 100, ...params }, ...QUIET })
 .then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<LeaveType>>(`/leaves/types/${id}`).then((r) => r.data.data),
 create: (data: CreateLeaveTypeData) =>
 client.post<ApiSuccess<LeaveType>>('/leaves/types', data).then((r) => r.data.data),
 update: (id: string, data: UpdateLeaveTypeData) =>
 client.put<ApiSuccess<LeaveType>>(`/leaves/types/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/leaves/types/${id}`),
  restore: (id: string) => client.patch(`/leaves/types/${id}/restore`),
  processYearEnd: (year?: number) =>
 client.post<{ message?: string }>('/leaves/process-year-end', { year }).then((r) => r.data),
};

export const leaveBalancesApi = {
 me: (year?: number) =>
 client.get<{ data: EmployeeLeaveBalance[] }>('/leaves/balances/me', { params: { year }, ...QUIET })
 .then((r) => r.data.data),
 forEmployee: (employeeId: string, year?: number) =>
 client.get<{ data: EmployeeLeaveBalance[] }>(`/leaves/balances/${employeeId}`, { params: { year }, ...QUIET })
 .then((r) => r.data.data),
};

export interface LeaveListParams extends ListParams {
 employee_id?: string;
 status?: string;
 from?: string;
 to?: string;
 search?: string;
}

export const leaveRequestsApi = {
 options: () => client.get<{ data: { statuses: Array<{ value: string; label: string }>; half_day_periods: Array<{ value: string; label: string }> } }>('/leaves/requests/options').then((r) => r.data.data),
 list: (params?: LeaveListParams) =>
 client.get<PaginatedResponse<LeaveRequest>>('/leaves/requests', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<LeaveRequest>>(`/leaves/requests/${id}`).then((r) => r.data.data),
 create: (data: CreateLeaveRequestData) =>
 client.post<ApiSuccess<LeaveRequest>>('/leaves/requests', data).then((r) => r.data.data),
 approveDept: (id: string, remarks?: string) =>
 client.patch<ApiSuccess<LeaveRequest>>(`/leaves/requests/${id}/approve-dept`, { remarks }).then((r) => r.data.data),
 approveHR: (id: string, remarks?: string) =>
 client.patch<ApiSuccess<LeaveRequest>>(`/leaves/requests/${id}/approve-hr`, { remarks }).then((r) => r.data.data),
 reject: (id: string, reason: string) =>
 client.patch<ApiSuccess<LeaveRequest>>(`/leaves/requests/${id}/reject`, { reason }).then((r) => r.data.data),
 cancel: (id: string) =>
 client.patch<ApiSuccess<LeaveRequest>>(`/leaves/requests/${id}/cancel`).then((r) => r.data.data),
};

export const leaveCalendarApi = {
 index: (params: { year?: number; month?: number; department_id?: string }) =>
 client.get<{ data: LeaveCalendarData }>('/leaves/calendar', { params }).then((r) => r.data.data),
};
