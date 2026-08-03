// Task 16 — Document Control API layer
import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { ControlledDocument, CreateDocumentData } from '@/types/quality/document';

export interface DocumentListParams extends ListParams {
  category?: string;
  is_active?: boolean;
}

export interface DocumentAssigneeRole { slug: string; name: string }

export const documentsApi = {
  options: () => client.get<{ data: { categories: Array<{ value: string; label: string }>; max_review_interval_months: number } }>('/quality/documents/options').then((r) => r.data.data),
  assigneeRoles: () => client.get<{ data: DocumentAssigneeRole[] }>('/quality/documents/assignee-roles').then((r) => r.data.data),
  list: (params?: DocumentListParams) =>
    client.get<PaginatedResponse<ControlledDocument>>('/quality/documents', { params }).then((r) => r.data),

  show: (id: string) =>
    client.get<ApiSuccess<ControlledDocument>>(`/quality/documents/${id}`).then((r) => r.data.data),

  create: (data: CreateDocumentData) =>
    client.post<ApiSuccess<ControlledDocument>>('/quality/documents', data).then((r) => r.data.data),

  update: (id: string, data: Partial<CreateDocumentData>) =>
    client.patch<ApiSuccess<ControlledDocument>>(`/quality/documents/${id}`, data).then((r) => r.data.data),

  publishRevision: (id: string, formData: FormData) =>
    client.post<ApiSuccess<ControlledDocument>>(`/quality/documents/${id}/revisions`, formData).then((r) => r.data.data),

  markReviewed: (id: string) =>
    client.post<ApiSuccess<ControlledDocument>>(`/quality/documents/${id}/mark-reviewed`).then((r) => r.data.data),
};
