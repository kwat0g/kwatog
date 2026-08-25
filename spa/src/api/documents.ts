/**
 * Series E (E1/E3) — document vault API client.
 *
 * Note: view_url and download_url are relative API paths returned by the
 * server. The SPA proxy keeps the Sanctum session cookie on the request.
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

 listForEntity: (entityType: 'employees', entityId: string, params?: Pick<ListDocumentsParams, 'per_page' | 'page'>) =>
  client
   .get<PaginatedResponse<DocumentRecord>>(`/documents/entity/${entityType}/${entityId}`, { params })
   .then((r) => r.data),

 show: (id: string) =>
 client
 .get<{ data: DocumentRecord }>(`/documents/${id}`)
 .then((r) => r.data.data),

 destroy: (id: string) => client.delete(`/documents/${id}`),

};
