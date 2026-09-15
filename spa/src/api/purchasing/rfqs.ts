import { unwrappingClient as client } from '../client';
import { createPortalClient } from '../b2b/client';
import type { PaginatedResponse } from '@/types';
import type { RequestForQuote, SupplierQuote } from '@/types/purchasing';

const { client: portalClient } = createPortalClient();

export interface CreateRfqData {
  title: string; instructions?: string; closes_at: string;
  invitations: Array<{ vendor_id: string; exception_reason?: string }>;
  specifications?: Record<string, string>; allow_partial_quantity?: Record<string, boolean>;
}

export const rfqsApi = {
  list: (params?: { page?: number; per_page?: number; status?: string; search?: string }, signal?: AbortSignal) =>
    client.get<PaginatedResponse<RequestForQuote>>('/purchasing/rfqs', { params, signal }).then((r) => r.data),
  show: (id: string) => client.get<RequestForQuote>(`/purchasing/rfqs/${id}`).then((r) => r.data),
  createFromPr: (prId: string, data: CreateRfqData) => client.post<RequestForQuote>(`/purchasing/purchase-requests/${prId}/rfqs`, data).then((r) => r.data),
  update: (id: string, data: Partial<CreateRfqData>) => client.put<RequestForQuote>(`/purchasing/rfqs/${id}`, data).then((r) => r.data),
  publish: (id: string) => client.post<RequestForQuote>(`/purchasing/rfqs/${id}/publish`).then((r) => r.data),
  extend: (id: string, closes_at: string, reason: string) => client.post<RequestForQuote>(`/purchasing/rfqs/${id}/extend`, { closes_at, reason }).then((r) => r.data),
  addendum: (id: string, data: { title: string; body: string; material_change?: boolean; extension_days?: number }) => client.post<RequestForQuote>(`/purchasing/rfqs/${id}/addenda`, data).then((r) => r.data),
  comparison: (id: string) => client.get<RequestForQuote>(`/purchasing/rfqs/${id}/comparison`).then((r) => r.data),
  award: (id: string, awards: Array<{ request_for_quote_item_id: string; supplier_quote_item_id: string; awarded_quantity: string; award_reason: string; single_response_justification?: string }>) => client.post<{ data: RequestForQuote; purchase_orders: unknown[] }>(`/purchasing/rfqs/${id}/award`, { awards }).then((r) => r.data),
  cancel: (id: string, reason: string) => client.post<RequestForQuote>(`/purchasing/rfqs/${id}/cancel`, { reason }).then((r) => r.data),
  purchaseOrders: (id: string) => client.get<unknown[]>(`/purchasing/rfqs/${id}/purchase-orders`).then((r) => r.data),
};

export const supplierRfqsApi = {
  list: (params?: { page?: number; per_page?: number }) => portalClient.get<PaginatedResponse<RequestForQuote>>('/b2b/supplier/rfqs', { params }).then((r) => r.data),
  show: (id: string) => portalClient.get<{ data: RequestForQuote }>(`/b2b/supplier/rfqs/${id}`).then((r) => r.data.data),
  saveQuote: (id: string, data: Record<string, unknown>) => portalClient.post<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${id}/quotes`, data).then((r) => r.data.data),
  updateQuote: (rfqId: string, quoteId: string, data: Record<string, unknown>) => portalClient.put<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${rfqId}/quotes/${quoteId}`, data).then((r) => r.data.data),
  submit: (rfqId: string, quoteId: string) => portalClient.post<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${rfqId}/quotes/${quoteId}/submit`).then((r) => r.data.data),
  withdraw: (rfqId: string, quoteId: string, reason: string) => portalClient.post<SupplierQuote>(`/b2b/supplier/rfqs/${rfqId}/quotes/${quoteId}/withdraw`, { reason }).then((r) => r.data),
  uploadDocument: (rfqId: string, form: FormData) => portalClient.post(`/b2b/supplier/rfqs/${rfqId}/documents`, form).then((r) => r.data),
};
