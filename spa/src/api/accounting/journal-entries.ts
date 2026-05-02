import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { CreateJournalEntryData, JournalEntry } from '@/types/accounting';

export interface JournalEntryListParams extends ListParams {
  status?: string;
  from?: string;
  to?: string;
  account_id?: string;
  reference_type?: string;
}

export const journalEntriesApi = {
  list: (params?: JournalEntryListParams) =>
    client.get<PaginatedResponse<JournalEntry>>('/journal-entries', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<JournalEntry>>(`/journal-entries/${id}`).then((r) => r.data.data),
  create: (data: CreateJournalEntryData) =>
    client.post<ApiSuccess<JournalEntry>>('/journal-entries', data).then((r) => r.data.data),
  update: (id: string, data: CreateJournalEntryData) =>
    client.put<ApiSuccess<JournalEntry>>(`/journal-entries/${id}`, data).then((r) => r.data.data),
  delete: (id: string) =>
    client.delete(`/journal-entries/${id}`),
  post: (id: string) =>
    client.patch<ApiSuccess<JournalEntry>>(`/journal-entries/${id}/post`).then((r) => r.data.data),
  reverse: (id: string, reverse_date?: string) =>
    client.post<ApiSuccess<JournalEntry>>(`/journal-entries/${id}/reverse`, { reverse_date }).then((r) => r.data.data),
  pdfUrl: (id: string) => `/api/v1/journal-entries/${id}/pdf`,
};
