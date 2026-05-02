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
  list: (params?: BillListParams) =>
    client.get<PaginatedResponse<Bill>>('/bills', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Bill>>(`/bills/${id}`).then((r) => r.data.data),
  create: (data: CreateBillData) =>
    client.post<ApiSuccess<Bill>>('/bills', data).then((r) => r.data.data),
  cancel: (id: string) =>
    client.patch<ApiSuccess<Bill>>(`/bills/${id}/cancel`).then((r) => r.data.data),
  recordPayment: (id: string, data: CreateBillPaymentData) =>
    client.post<ApiSuccess<BillPayment>>(`/bills/${id}/payments`, data).then((r) => r.data.data),
  pdfUrl: (id: string) => `/api/v1/bills/${id}/pdf`,
};
