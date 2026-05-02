import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { PurchaseRequest, CreatePurchaseRequestData, PurchaseOrder } from '@/types/purchasing';

export const purchaseRequestsApi = {
  list: (params?: ListParams & { status?: string; priority?: string; is_auto_generated?: boolean | string; from?: string; to?: string }) =>
    client.get<PaginatedResponse<PurchaseRequest>>('/purchasing/purchase-requests', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PurchaseRequest>>(`/purchasing/purchase-requests/${id}`).then((r) => r.data.data),
  create: (data: CreatePurchaseRequestData) =>
    client.post<ApiSuccess<PurchaseRequest>>('/purchasing/purchase-requests', data).then((r) => r.data.data),
  update: (id: string, data: Partial<CreatePurchaseRequestData>) =>
    client.put<ApiSuccess<PurchaseRequest>>(`/purchasing/purchase-requests/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/purchasing/purchase-requests/${id}`),
  submit: (id: string) =>
    client.patch<ApiSuccess<PurchaseRequest>>(`/purchasing/purchase-requests/${id}/submit`).then((r) => r.data.data),
  approve: (id: string, remarks?: string) =>
    client.patch<ApiSuccess<PurchaseRequest>>(`/purchasing/purchase-requests/${id}/approve`, { remarks }).then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client.patch<ApiSuccess<PurchaseRequest>>(`/purchasing/purchase-requests/${id}/reject`, { reason }).then((r) => r.data.data),
  cancel: (id: string) =>
    client.patch<ApiSuccess<PurchaseRequest>>(`/purchasing/purchase-requests/${id}/cancel`).then((r) => r.data.data),
  convert: (id: string, vendor_map: Record<number, number>) =>
    client.post<{ data: PurchaseOrder[] }>(`/purchasing/purchase-requests/${id}/convert`, { vendor_map }).then((r) => r.data.data),
};
