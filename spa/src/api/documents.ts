/**
 * Series E (E1/E3) — document vault API client.
 *
 * Note: view_url and download_url are absolute URLs returned by the
 * API. They include the auth cookie when navigated via <a href>, so we
 * never need to fetch the blob in JS just to display it.
 */

import { client } from './client';
import type { PaginatedResponse } from '@/types';
import type { DocumentRecord, DocumentType } from '@/types/documents';

export interface ListDocumentsParams {
  document_type?: DocumentType;
  entity_type?: string;
  from?: string;
  to?: string;
  page?: number;
  per_page?: number;
}

export const documentsApi = {
  list: (params?: ListDocumentsParams) =>
    client
      .get<PaginatedResponse<DocumentRecord>>('/documents', { params })
      .then((r) => r.data),

  show: (id: string) =>
    client
      .get<{ data: DocumentRecord }>(`/documents/${id}`)
      .then((r) => r.data.data),

  destroy: (id: string) => client.delete(`/documents/${id}`),
};
