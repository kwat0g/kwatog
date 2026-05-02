import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { LeaveType, LeaveRequest, EmployeeLeaveBalance, CreateLeaveRequestData } from '@/types/leave';

// Opt-out so transient backend hiccups on these reference queries don't spam
// the global "Something went wrong" toast — the page handles empty state.
const QUIET = { skipErrorToast: true } as const;

export const leaveTypesApi = {
  list: () =>
    client.get<PaginatedResponse<LeaveType>>('/leaves/types', { params: { per_page: 100 }, ...QUIET })
      .then((r) => r.data),
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
}

export const leaveRequestsApi = {
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
