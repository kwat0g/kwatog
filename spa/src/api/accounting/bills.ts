import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Bill, BillPayment, CreateBillData, CreateBillPaymentData } from '@/types/accounting';

export interface BillListParams extends ListParams {
 status?: string;
 vendor_id?: string;
 from?: string;
 to?: string;
 overdue?: boolean | string;
}

export const billsApi = {
 options: () => client.get<{ data: { statuses: Array<{ value: string; label: string }> } }>('/bills/options').then((r) => r.data.data),
 list: (params?: BillListParams) =>
 client.get<PaginatedResponse<Bill>>('/bills', { params }).then((r) => r.data),
 show: (id: string) =>
 client.get<ApiSuccess<Bill>>(`/bills/${id}`).then((r) => r.data.data),
 create: (data: CreateBillData) =>
 client.post<ApiSuccess<Bill>>('/bills', data).then((r) => r.data.data),
 postDraft: (id: string, data?: { allow_override?: boolean; override_reason?: string }) =>
 client.post<ApiSuccess<Bill>>(`/bills/${id}/post`, data).then((r) => r.data.data),
 cancel: (id: string) =>
 client.patch<ApiSuccess<Bill>>(`/bills/${id}/cancel`).then((r) => r.data.data),
  recordPayment: (id: string, data: CreateBillPaymentData, idempotencyKey: string) => {
  const body = new FormData();
  body.append('cash_account_id', data.cash_account_id);
  body.append('payment_date', data.payment_date);
  body.append('amount', data.amount);
  body.append('payment_method', data.payment_method);
  body.append('reference_number', data.reference_number);
  if (data.proof_file) body.append('proof_file', data.proof_file);
  return client.post<ApiSuccess<BillPayment>>(`/bills/${id}/payments`, body, {
    headers: { 'Idempotency-Key': idempotencyKey },
  }).then((r) => r.data.data);
  },
  paymentProofUrl: (billId: string, paymentId: string) =>
  `/api/v1/bills/${billId}/payments/${paymentId}/proof`,
  approvePayment: (billId: string, paymentId: string) =>
  client.post<ApiSuccess<BillPayment>>(`/bills/${billId}/payments/${paymentId}/approve`).then((r) => r.data.data),
  rejectPayment: (billId: string, paymentId: string, remarks: string) =>
  client.post<ApiSuccess<BillPayment>>(`/bills/${billId}/payments/${paymentId}/reject`, { remarks }).then((r) => r.data.data),
 voidPayment: (billId: string, paymentId: string, data: { void_date?: string; reason: string; replacement_payment_id?: string }) =>
 client.post<ApiSuccess<BillPayment>>(`/bills/${billId}/payments/${paymentId}/void`, data).then((r) => r.data.data),
 pdfUrl: (id: string) => `/api/v1/bills/${id}/pdf`,
 supplierInvoiceUrl: (id: string, documentId: string) =>
 `/api/v1/bills/${id}/supplier-invoice-attachment/${documentId}`,
};
