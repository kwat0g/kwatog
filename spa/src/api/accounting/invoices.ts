import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { Collection, CreateCollectionData, CreateInvoiceData, Invoice } from '@/types/accounting';

export interface InvoiceListParams extends ListParams {
  status?: string;
  customer_id?: string;
  from?: string;
  to?: string;
  overdue?: boolean | string;
}

export const invoicesApi = {
  list: (params?: InvoiceListParams) =>
    client.get<PaginatedResponse<Invoice>>('/invoices', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<Invoice>>(`/invoices/${id}`).then((r) => r.data.data),
  create: (data: CreateInvoiceData) =>
    client.post<ApiSuccess<Invoice>>('/invoices', data).then((r) => r.data.data),
  update: (id: string, data: CreateInvoiceData) =>
    client.put<ApiSuccess<Invoice>>(`/invoices/${id}`, data).then((r) => r.data.data),
  finalize: (id: string) =>
    client.patch<ApiSuccess<Invoice>>(`/invoices/${id}/finalize`).then((r) => r.data.data),
  cancel: (id: string) =>
    client.patch<ApiSuccess<Invoice>>(`/invoices/${id}/cancel`).then((r) => r.data.data),
  recordCollection: (id: string, data: CreateCollectionData) =>
    client.post<ApiSuccess<Collection>>(`/invoices/${id}/collections`, data).then((r) => r.data.data),
  pdfUrl: (id: string) => `/api/v1/invoices/${id}/pdf`,
};
