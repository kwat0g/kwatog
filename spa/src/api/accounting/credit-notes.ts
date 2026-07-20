import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ApplyCreditNoteData, CreateCreditNoteData, CreditNote } from '@/types/accounting';

export interface CreditNoteListParams extends ListParams {
  type?: string;
  status?: string;
  customer_id?: string;
  vendor_id?: string;
}

export const creditNotesApi = {
  list: (params?: CreditNoteListParams) =>
    client.get<PaginatedResponse<CreditNote>>('/accounting/credit-notes', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<CreditNote>>(`/accounting/credit-notes/${id}`).then((r) => r.data.data),
  create: (data: CreateCreditNoteData) =>
    client.post<ApiSuccess<CreditNote>>('/accounting/credit-notes', data).then((r) => r.data.data),
  finalize: (id: string) =>
    client.post<ApiSuccess<CreditNote>>(`/accounting/credit-notes/${id}/finalize`).then((r) => r.data.data),
  apply: (id: string, data: ApplyCreditNoteData) =>
    client.post<ApiSuccess<CreditNote>>(`/accounting/credit-notes/${id}/apply`, data).then((r) => r.data.data),
};
