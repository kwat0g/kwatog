import { client } from './client';
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

/** U3 — Self-service portal endpoints (always scoped to current user). */
export const selfServiceApi = {
  home: () =>
    client.get<{ data: SelfServiceHome }>('/hr/self-service/home').then((r) => r.data.data),

  loans: () =>
    client
      .get<{ data: SelfServiceLoansResponse }>('/hr/self-service/loans')
      .then((r) => r.data.data),

  applyLoan: (data: { loan_type: string; amount: number; periods: number; reason?: string }) =>
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
    client.get<{ data: SelfServiceLeaveType[] }>('/leaves/types').then((r) => r.data.data),

  leaveBalancesMe: () =>
    client
      .get<{ data: SelfServiceLeaveBalanceSelf[] }>('/leaves/balances/me')
      .then((r) => r.data.data),

  fileLeaveSelf: (payload: FileLeavePayload) =>
    client
      .post<{ message: string; data: { id: string } }>('/leaves/requests', payload)
      .then((r) => r.data),

  // ─── Loan amortization preview (Task SS-LP) ─────────────────────
  previewLoanAmortization: (principal: number, periods: number) =>
    client
      .post<{ data: LoanAmortizationPreview }>('/loans/preview-amortization', {
        principal: principal.toFixed(2),
        pay_periods: periods,
      })
      .then((r) => r.data.data),
};
