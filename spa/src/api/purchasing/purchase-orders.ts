import { unwrappingClient as client } from '../client';
import type { PaginatedResponse, ListParams } from '@/types';
import type { PurchaseOrder, CreatePurchaseOrderData, ThreeWayMatchResult, ProcurementChainOverview, PurchaseOrderResponse } from '@/types/purchasing';

export const purchaseOrdersApi = {
 options: () => client.get<{
  statuses: Array<{ value: string; label: string }>;
  incoterms: Array<{ value: string; label: string }>;
  approval_sla_hours?: number;
 }>('/purchasing/purchase-orders/options').then((r) => r.data),
 list: (params?: ListParams & { status?: string; vendor_id?: string; requires_vp_approval?: boolean | string; from?: string; to?: string }, signal?: AbortSignal) =>
 client.get<PaginatedResponse<PurchaseOrder>>('/purchasing/purchase-orders', { params, signal }).then((r) => r.data),
 show: (id: string) =>
 client.get<PurchaseOrder>(`/purchasing/purchase-orders/${id}`).then((r) => r.data),
 create: (data: CreatePurchaseOrderData) =>
 client.post<PurchaseOrder>('/purchasing/purchase-orders', data).then((r) => r.data),
 update: (id: string, data: Partial<CreatePurchaseOrderData>) =>
 client.put<PurchaseOrder>(`/purchasing/purchase-orders/${id}`, data).then((r) => r.data),
 delete: (id: string) => client.delete(`/purchasing/purchase-orders/${id}`),
 restore: (id: string) => client.patch(`/purchasing/purchase-orders/${id}/restore`),
 submit: (id: string) =>
 client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/submit`).then((r) => r.data),
 acknowledgeBudget: (id: string) =>
 client.patch<PurchaseOrder>(`/purchasing/purchase-orders/${id}/acknowledge-budget`).then((r) => r.data),
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

 // ── Supplier responses ──────────────────────────────
 /** All supplier responses on a PO, newest first. */
 responses: (id: string) =>
 client.get<PurchaseOrderResponse[]>(`/purchasing/purchase-orders/${id}/responses`).then((r) => r.data),
 /** Accept a supplier's response (records the confirmed date/quantities/prices). */
 acceptResponse: (responseId: string) =>
 client.patch<PurchaseOrderResponse>(`/purchasing/purchase-order-responses/${responseId}/accept`).then((r) => r.data),
 /** Reject a supplier's response with a reason. */
 rejectResponse: (responseId: string, reason: string) =>
 client.patch<PurchaseOrderResponse>(`/purchasing/purchase-order-responses/${responseId}/reject`, { reason }).then((r) => r.data),
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
