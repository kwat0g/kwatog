import { client } from '../client';
import type { ApiSuccess, PaginatedResponse, ListParams } from '@/types';
import type { PurchaseOrder, CreatePurchaseOrderData, ThreeWayMatchResult } from '@/types/purchasing';

export const purchaseOrdersApi = {
  list: (params?: ListParams & { status?: string; vendor_id?: string; requires_vp_approval?: boolean | string; from?: string; to?: string }) =>
    client.get<PaginatedResponse<PurchaseOrder>>('/purchasing/purchase-orders', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}`).then((r) => r.data.data),
  create: (data: CreatePurchaseOrderData) =>
    client.post<ApiSuccess<PurchaseOrder>>('/purchasing/purchase-orders', data).then((r) => r.data.data),
  update: (id: string, data: Partial<CreatePurchaseOrderData>) =>
    client.put<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}`, data).then((r) => r.data.data),
  delete: (id: string) => client.delete(`/purchasing/purchase-orders/${id}`),
  submit: (id: string) =>
    client.patch<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}/submit`).then((r) => r.data.data),
  approve: (id: string, remarks?: string) =>
    client.patch<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}/approve`, { remarks }).then((r) => r.data.data),
  reject: (id: string, reason: string) =>
    client.patch<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}/reject`, { reason }).then((r) => r.data.data),
  send: (id: string) =>
    client.patch<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}/send`).then((r) => r.data.data),
  cancel: (id: string, reason: string) =>
    client.patch<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}/cancel`, { reason }).then((r) => r.data.data),
  close: (id: string) =>
    client.patch<ApiSuccess<PurchaseOrder>>(`/purchasing/purchase-orders/${id}/close`).then((r) => r.data.data),
  pdfUrl: (id: string) => `/api/v1/purchasing/purchase-orders/${id}/pdf`,
};

export const threeWayMatchApi = {
  forBill: (billId: string) =>
    client.get<{ data: ThreeWayMatchResult | null }>(`/purchasing/three-way-match/${billId}`).then((r) => r.data.data),
};
