import { client } from './client';
import type { PaginatedResponse } from '@/types';
import type { LeaveRequest } from '@/types/leave';
import type { Payroll } from '@/types/payroll';
import type {
 SelfServiceHome,
 SelfServiceLoansResponse,
 SelfServiceProfile,
 ProfileUpdateRequestRecord,
 SelfServiceOvertimeResponse,
 ApplyOvertimePayload,
 SelfServiceDocumentsResponse,
 SelfServiceLeaveType,
 SelfServiceLeaveBalanceSelf,
 FileLeavePayload,
 LoanAmortizationPreview,
} from '@/types/self-service';

export interface SelfServiceAttendanceRow {
 id: string;
 date: string;
 time_in: string | null;
 time_out: string | null;
 regular_hours: string | number | null;
 overtime_hours?: string | number | null;
 status?: string;
}

/** U3 — Self-service portal endpoints (always scoped to current user). */
export const selfServiceApi = {
 home: () =>
 client.get<{ data: SelfServiceHome }>('/hr/self-service/home').then((r) => r.data.data),

 attendance: (params: { from: string; to: string; per_page?: number }) =>
 client
 .get<PaginatedResponse<SelfServiceAttendanceRow>>('/hr/self-service/attendance', { params })
 .then((r) => r.data),

 attendanceOptions: () =>
 client
 .get<{ data: { statuses: Array<{ value: string; label: string }> } }>('/hr/self-service/attendance/options')
 .then((r) => r.data.data),

 leaveRequests: (params?: { per_page?: number }) =>
 client
 .get<PaginatedResponse<LeaveRequest>>('/hr/self-service/leave-requests', { params })
 .then((r) => r.data),

 payslips: (params?: { page?: number; per_page?: number; sort?: string; direction?: 'asc' | 'desc' }) =>
 client
 .get<PaginatedResponse<Payroll>>('/hr/self-service/payslips', { params })
 .then((r) => r.data),

 payslipUrl: (id: string) => `/api/v1/hr/self-service/payslips/${id}/download`,

 loans: () =>
 client
 .get<{ data: SelfServiceLoansResponse }>('/hr/self-service/loans')
 .then((r) => r.data.data),

 applyLoan: (data: { loan_type: string; amount: string | number; periods: number; reason?: string }) =>
 client
 .post<{ message: string; data: { id: string } }>('/hr/self-service/loans', data)
 .then((r) => r.data),

 profile: () =>
 client.get<{ data: SelfServiceProfile }>('/hr/self-service/profile').then((r) => r.data.data),

 requestProfileUpdate: (changes: Record<string, string | null>, note?: string) =>
 client
 .post<{ message: string; data: { id: string; status: string } }>(
 '/hr/self-service/profile/request-update',
 { changes, note },
 )
 .then((r) => r.data),

 profileUpdateRequests: () =>
 client
 .get<{ data: ProfileUpdateRequestRecord[] }>('/hr/self-service/profile/update-requests')
 .then((r) => r.data.data),

 // ─── Overtime (SS1) ─────────────────────────────────────────────
 overtime: () =>
 client
 .get<{ data: SelfServiceOvertimeResponse }>('/hr/self-service/overtime')
 .then((r) => r.data.data),

 applyOvertime: (payload: ApplyOvertimePayload) =>
 client
 .post<{ message: string; data: { id: string; status: string } }>(
 '/hr/self-service/overtime',
 payload,
 )
 .then((r) => r.data),

 cancelOvertime: (id: string) =>
 client
 .delete<{ message: string }>(`/hr/self-service/overtime/${id}`)
 .then((r) => r.data),

 restoreOvertime: (id: string) =>
 client
 .patch<{ message: string; data: { id: string; status: string } }>(
 '/hr/self-service/overtime/' + id + '/restore',
 )
 .then((r) => r.data),

 // ─── Documents (SS3) ────────────────────────────────────────────
 documents: () =>
 client
 .get<{ data: SelfServiceDocumentsResponse }>('/hr/self-service/documents')
 .then((r) => r.data.data),

 /**
 * Absolute URLs for PDF downloads. The browser sends the session cookie on
 * <a href> navigation, so we never fetch the blob in JS — matches the
 * payslip download pattern.
 */
 employmentCertificateUrl: (withSalary = false) =>
 `/api/v1/hr/self-service/documents/employment-certificate${withSalary ? '?with_salary=1' : ''}`,
 contributionCertificateUrl: (type: 'sss' | 'philhealth' | 'pagibig', year?: number) =>
 `/api/v1/hr/self-service/documents/contributions/${type}${year ? `?year=${year}` : ''}`,
 bir2316Url: (year?: number) =>
 `/api/v1/hr/self-service/documents/bir-2316${year ? `?year=${year}` : ''}`,

 // ─── Leave filing (Task SS-LF) ──────────────────────────────────
 leaveTypes: () =>
 client.get<{ data: SelfServiceLeaveType[] }>('/leaves/types', { params: { is_active: 'true' } }).then((r) => r.data.data),

 leaveBalancesMe: () =>
 client
 .get<{ data: SelfServiceLeaveBalanceSelf[] }>('/leaves/balances/me')
 .then((r) => r.data.data),

 fileLeaveSelf: (payload: FileLeavePayload) =>
 client
 .post<{ message: string; data: { id: string } }>('/leaves/requests', (() => {
 const form = new FormData();
 form.append('employee_id', payload.employee_id);
 form.append('leave_type_id', payload.leave_type_id);
 form.append('start_date', payload.start_date);
 form.append('end_date', payload.end_date);
 if (payload.half_day_period) form.append('half_day_period', payload.half_day_period);
 if (payload.reason) form.append('reason', payload.reason);
 if (payload.document) form.append('document', payload.document);
 return form;
 })())
 .then((r) => r.data),

 // ─── Loan amortization preview (Task SS-LP) ─────────────────────
 previewLoanAmortization: (loan_type: string, principal: string | number, periods: number) =>
 client
 .post<{ data: LoanAmortizationPreview }>('/loans/preview-amortization', {
 loan_type,
 principal: String(principal),
 pay_periods: periods,
 })
 .then((r) => r.data.data),
};
