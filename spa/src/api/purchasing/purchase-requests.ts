import { client } from '../client';
import type { PaginatedResponse, ListParams } from '@/types';
import type { PurchaseRequest, CreatePurchaseRequestData, PurchaseOrder, PurchaseRequestTemplate } from '@/types/purchasing';

export const purchaseRequestsApi = {
  list: (params?: ListParams & { status?: string; priority?: string; is_auto_generated?: boolean | string; is_urgent?: boolean | string; from?: string; to?: string }) =>
    client.get<PaginatedResponse<PurchaseRequest>>('/purchasing/purchase-requests', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<PurchaseRequest>(`/purchasing/purchase-requests/${id}`).then((r) => r.data),
  create: (data: CreatePurchaseRequestData) =>
    client.post<PurchaseRequest>('/purchasing/purchase-requests', data).then((r) => r.data),
  update: (id: string, data: Partial<CreatePurchaseRequestData>) =>
    client.put<PurchaseRequest>(`/purchasing/purchase-requests/${id}`, data).then((r) => r.data),
  delete: (id: string) => client.delete(`/purchasing/purchase-requests/${id}`),
  submit: (id: string) =>
    client.patch<PurchaseRequest>(`/purchasing/purchase-requests/${id}/submit`).then((r) => r.data),
  approve: (id: string, remarks?: string) =>
    client.patch<PurchaseRequest>(`/purchasing/purchase-requests/${id}/approve`, { remarks }).then((r) => r.data),
  reject: (id: string, reason: string) =>
    client.patch<PurchaseRequest>(`/purchasing/purchase-requests/${id}/reject`, { reason }).then((r) => r.data),
  cancel: (id: string) =>
    client.patch<PurchaseRequest>(`/purchasing/purchase-requests/${id}/cancel`).then((r) => r.data),
  convert: (id: string, vendor_map: Record<number, number>) =>
    client.post<PurchaseOrder[]>(`/purchasing/purchase-requests/${id}/convert`, { vendor_map }).then((r) => r.data),

  /** ADV6 — Bulk approve multiple PRs at once. Accepts hash ID strings. */
  bulkApprove: (ids: string[], remarks?: string) =>
    client.post<Array<{ id: string; status: string; message: string | null }>>('/purchasing/purchase-requests/bulk-approve', { ids, remarks }).then((r) => r.data),

  /** ADV6 — Pending PR count for the sidebar badge. */
  pendingCount: () =>
    client.get<{ count: number }>('/purchasing/purchase-requests/pending-count').then((r) => r.data),

  /** Sprint P9 — printable PR with 4-tier approval signature block. */
  pdfUrl: (id: string) => `/api/v1/purchasing/purchase-requests/${id}/pdf`,
};

/** ADV6 — PR Template CRUD. */
export const prTemplatesApi = {
  list: (params?: ListParams & { is_active?: boolean | string; search?: string }) =>
    client.get<PaginatedResponse<PurchaseRequestTemplate>>('/purchasing/pr-templates', { params }).then((r) => r.data),
  active: () =>
    client.get<PurchaseRequestTemplate[]>('/purchasing/pr-templates/active').then((r) => r.data),
  show: (id: number) =>
    client.get<PurchaseRequestTemplate>(`/purchasing/pr-templates/${id}`).then((r) => r.data),
  create: (data: Partial<PurchaseRequestTemplate> & { items: PurchaseRequestTemplate['items'] }) =>
    client.post<PurchaseRequestTemplate>('/purchasing/pr-templates', data).then((r) => r.data),
  update: (id: number, data: Partial<PurchaseRequestTemplate>) =>
    client.put<PurchaseRequestTemplate>(`/purchasing/pr-templates/${id}`, data).then((r) => r.data),
  delete: (id: number) => client.delete(`/purchasing/pr-templates/${id}`),
};
