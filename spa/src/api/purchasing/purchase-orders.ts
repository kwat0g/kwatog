import { client } from '../client';
import type { PaginatedResponse, ListParams } from '@/types';
import type { PurchaseOrder, CreatePurchaseOrderData, ThreeWayMatchResult, ProcurementChainOverview } from '@/types/purchasing';

export const purchaseOrdersApi = {
  list: (params?: ListParams & { status?: string; vendor_id?: string; requires_vp_approval?: boolean | string; from?: string; to?: string }) =>
    client.get<PaginatedResponse<PurchaseOrder>>('/purchasing/purchase-orders', { params }).then((r) => r.data),
  show: (id: string) =>
    client.get<PurchaseOrder>(`/purchasing/purchase-orders/${id}`).then((r) => r.data),
  create: (data: CreatePurchaseOrderData) =>
    client.post<PurchaseOrder>('/purchasing/purchase-orders', data).then((r) => r.data),
  update: (id: string, data: Partial<CreatePurchaseOrderData>) =>
    client.put<PurchaseOrder>(`/purchasing/purchase-orders/${id}`, data).then((r) => r.data),
  delete: (id: string) => client.delete(`/purchasing/purchase-orders/${id}`),
  submit: (id: string) =>
    client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/submit`).then((r) => r.data),
  approve: (id: string, remarks?: string) =>
    client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/approve`, { remarks }).then((r) => r.data),
  reject: (id: string, reason: string) =>
    client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/reject`, { reason }).then((r) => r.data),
  send: (id: string) =>
    client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/send`).then((r) => r.data),
  cancel: (id: string, reason: string) =>
    client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/cancel`, { reason }).then((r) => r.data),
  close: (id: string) =>
    client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/close`).then((r) => r.data),
  pdfUrl: (id: string) => `/api/v1/purchasing/purchase-orders/${id}/pdf`,
};

/* ─── ADV5 — Procurement Chain overview ─── */
export const procurementChainApi = {
  overview: () =>
    client.get<ProcurementChainOverview>('/purchasing/chain').then((r) => r.data),
};

export const threeWayMatchApi = {
  forBill: (billId: string) =>
    client.get<ThreeWayMatchResult | null>(`/purchasing/three-way-match/${billId}`).then((r) => r.data),
};
