import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { BankFilePreview, CreatePayrollPeriodData, DisbursementProof, PayrollPeriod, PayrollPipeline, PayrollScopePreview, PayrollVarianceReport, ProofType } from '@/types/payroll';

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

export interface BankFileFormatOption {
 value: string;
 label: string;
}

export const periodsApi = {
 options: () =>
 client
 .get<{
 data: {
 statuses: Array<{ value: string; label: string }>;
 period_types: Array<{ value: string; label: string }>;
 half_types: Array<{ value: string; label: string }>;
 employment_types: Array<{ value: string; label: string }>;
 pay_types: Array<{ value: string; label: string }>;
 departments: Array<{ value: string; label: string }>;
 };
 }>('/payroll-periods/options')
 .then((r) => r.data.data),
 /** Dry-run a scope before creating the period. */
 scopePreview: (data: Partial<CreatePayrollPeriodData>) =>
 client.post<{ data: PayrollScopePreview }>('/payroll-periods/scope-preview', data).then((r) => r.data.data),
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
 forceUnlock: (id: string, reason?: string) =>
 client
 .post<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/force-unlock`, { reason: reason ?? '' })
 .then((r) => r.data.data),
 // REC-01 — void a finalized period (reverses GL, transitions to Voided).
 void: (id: string, reason: string) =>
 client
 .post<ApiSuccess<PayrollPeriod>>(`/payroll-periods/${id}/void`, { reason })
 .then((r) => r.data.data),
 bankFileOptions: () =>
 client.get<{ data: { formats: BankFileFormatOption[]; default_format: string } }>('/payroll-periods/bank-file/options').then((r) => r.data.data),
 /**
  * Dry-run the bank file: row count, total, and anyone who would be silently
  * left out for want of bank details. Checked before the download is offered,
  * since a short file is money nobody notices until an employee reports
  * missing pay.
  */
 bankFilePreview: (id: string, format?: string) =>
   client
     .get<{ data: BankFilePreview }>(`/payroll-periods/${id}/bank-file/preview`, {
       params: format ? { format } : undefined,
     })
     .then((r) => r.data.data),
 bankFileUrl: (id: string, format?: string) =>
 `/api/v1/payroll-periods/${id}/bank-file${format ? `?format=${encodeURIComponent(format)}` : ''}`,
 runThirteenthMonth: (year: number, payroll_date?: string) =>
 client
 .post<ApiSuccess<PayrollPeriod>>('/payroll-periods/thirteenth-month', { year, payroll_date })
 .then((r) => r.data.data),

 // ADV1 — Disbursement proof CRUD
 proofOptions: (periodId: string) =>
 client.get<{ data: { proof_types: Array<{ value: ProofType; label: string }> } }>(`/payroll-periods/${periodId}/disbursement-proofs/options`).then((r) => r.data.data),
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
 restoreProof: (periodId: string, proofId: string) =>
 client.patch(`/payroll-periods/${periodId}/disbursement-proofs/${proofId}/restore`),

 // CA3 — Payroll pipeline (full-year view) — kept: referenced by the retained
 // pipeline.tsx page file (dead code per hide-access policy).
 pipeline: (year?: number) =>
 client.get<{ data: PayrollPipeline }>('/payroll-periods/pipeline', { params: year ? { year } : undefined }).then((r) => r.data.data),

 // BIR 2316 alphalist moved to statutoryApi.bir2316Alphalist (2026-08-08) —
 // the export lives on the Statutory Exports page behind payroll.statutory.export.

 // Task 9 — Period-over-period variance report
 variance: (id: string, compareTo: string) =>
 client
 .get<{ data: PayrollVarianceReport }>(`/payroll-periods/${id}/variance?compare_to=${compareTo}`)
 .then((r) => r.data.data),
};
