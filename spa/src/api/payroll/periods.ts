import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreatePayrollPeriodData, DisbursementProof, PayrollPeriod, PayrollPipeline, PayrollVarianceReport, ProofType } from '@/types/payroll';

export interface PeriodListParams extends ListParams {
  status?: string;
  year?: number | string;
  is_first_half?: boolean | string;
  is_thirteenth_month?: boolean | string;
}

export interface UploadProofData {
  proof_type: ProofType;
  bank_name?: string;
  transaction_reference?: string;
  disbursed_amount?: number;
  disbursement_date: string;
  notes?: string;
}

export const periodsApi = {
  list: (params?: PeriodListParams) =>
    client.get<PaginatedResponse<PayrollPeriod>>('/payroll-periods', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}`).then((r) => r.data.data),
  create: (data: CreatePayrollPeriodData) =>
    client.post<ApiSuccess<PayrollPeriod>>('/payroll-periods', data).then((r) => r.data.data),
  compute: (id: string) =>
    client.post<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/compute`).then((r) => r.data.data),
  approve: (id: string) =>
    client.patch<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/approve`).then((r) => r.data.data),
  finalize: (id: string) =>
    client.patch<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/finalize`).then((r) => r.data.data),
  markDisbursed: (id: string) =>
    client.patch<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/mark-disbursed`).then((r) => r.data.data),
  bankFileUrl: (id: string) => `/api/v1/payroll-periods/${id}/bank-file`,
  runThirteenthMonth: (year: number, payroll_date?: string) =>
    client
      .post<ApiSuccess<PayrollPeriod>>('/payroll-periods/thirteenth-month', { year, payroll_date })
      .then((r) => r.data.data),

  // ADV1 — Disbursement proof CRUD
  listProofs: (periodId: string) =>
    client
      .get<{ data: DisbursementProof[] }>(`/payroll-periods/${periodId}/disbursement-proofs`)
      .then((r) => r.data.data),
  uploadProof: (periodId: string, data: FormData) =>
    client
      .post<ApiSuccess<DisbursementProof>>(`/payroll-periods/${periodId}/disbursement-proofs`, data, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })
      .then((r) => r.data.data),
  downloadProof: (periodId: string, proofId: string) =>
    `/api/v1/payroll-periods/${periodId}/disbursement-proofs/${proofId}`,
  deleteProof: (periodId: string, proofId: string) =>
    client.delete(`/payroll-periods/${periodId}/disbursement-proofs/${proofId}`),

  // CA3 — Payroll pipeline (full-year view)
  pipeline: (year?: number) =>
    client.get<{ data: PayrollPipeline }>('/payroll-periods/pipeline', { params: year ? { year } : undefined }).then((r) => r.data.data),

  // Task 6 — BIR 2316 Alphalist CSV export
  downloadBirAlphalist: (year: number) =>
    client.get(`/payroll/bir-alphalist?year=${year}`, { responseType: 'blob' }),

  // Task 9 — Period-over-period variance report
  variance: (id: string, compareTo: string) =>
    client
      .get<{ data: PayrollVarianceReport }>(`/payroll-periods/${id}/variance?compare_to=${compareTo}`)
      .then((r) => r.data.data),
};
