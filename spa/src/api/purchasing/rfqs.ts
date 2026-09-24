import { unwrappingClient as client } from '../client';
import { createPortalClient } from '../b2b/client';
import type { PaginatedResponse } from '@/types';
import type {
  PurchaseOrder,
  RequestForQuote,
  RfqDocument,
  RfqSetup,
  RfqWrite,
  SupplierQuote,
  SupplierQuoteWrite,
  SupplierRfq,
} from '@/types/purchasing';

// The portal client does not unwrap Laravel's { data } envelope; the internal
// unwrappingClient does (single-key envelopes only).
const { client: portalClient } = createPortalClient('supplier');

export const rfqsApi = {
  list: (
    params?: { page?: number; per_page?: number; status?: string; search?: string },
    signal?: AbortSignal,
  ) =>
    client
      .get<PaginatedResponse<RequestForQuote>>('/purchasing/rfqs', { params, signal })
      .then((r) => r.data),

  /** Lines still to source on the PR, and the suppliers to invite. */
  setup: (prId: string) =>
    client.get<RfqSetup>(`/purchasing/purchase-requests/${prId}/rfq-setup`).then((r) => r.data),

  createFromPr: (prId: string, data: RfqWrite & { publish?: boolean }) =>
    client
      .post<RequestForQuote>(`/purchasing/purchase-requests/${prId}/rfqs`, data)
      .then((r) => r.data),

  show: (id: string) => client.get<RequestForQuote>(`/purchasing/rfqs/${id}`).then((r) => r.data),

  update: (id: string, data: Partial<RfqWrite>) =>
    client.put<RequestForQuote>(`/purchasing/rfqs/${id}`, data).then((r) => r.data),

  publish: (id: string) =>
    client.post<RequestForQuote>(`/purchasing/rfqs/${id}/publish`).then((r) => r.data),

  extend: (id: string, closes_at: string, reason: string) =>
    client
      .post<RequestForQuote>(`/purchasing/rfqs/${id}/extend`, { closes_at, reason })
      .then((r) => r.data),

  closeNow: (id: string) =>
    client.post<RequestForQuote>(`/purchasing/rfqs/${id}/close`).then((r) => r.data),

  cancel: (id: string, reason: string) =>
    client.post<RequestForQuote>(`/purchasing/rfqs/${id}/cancel`, { reason }).then((r) => r.data),

  uploadDocument: (id: string, form: FormData) =>
    client.post<RfqDocument>(`/purchasing/rfqs/${id}/documents`, form).then((r) => r.data),

  manualQuote: (id: string, data: SupplierQuoteWrite & { vendor_id: string }) =>
    client.post<SupplierQuote>(`/purchasing/rfqs/${id}/quotes/manual`, data).then((r) => r.data),

  comparison: (id: string) =>
    client.get<RequestForQuote>(`/purchasing/rfqs/${id}/comparison`).then((r) => r.data),

  award: (
    id: string,
    data: {
      award_reason: string;
      lines: Array<{ request_for_quote_item_id: string; supplier_quote_item_id: string }>;
    },
  ) =>
    client
      .post<{
        data: RequestForQuote;
        purchase_orders: PurchaseOrder[];
      }>(`/purchasing/rfqs/${id}/award`, data)
      .then((r) => r.data),

  purchaseOrders: (id: string) =>
    client.get<PurchaseOrder[]>(`/purchasing/rfqs/${id}/purchase-orders`).then((r) => r.data),

  documentDownloadUrl: (documentId: string) =>
    `/api/v1/purchasing/rfq-documents/${documentId}/download`,
};

export const supplierRfqsApi = {
  list: (params?: {
    page?: number;
    per_page?: number;
    status?: string;
    search?: string;
    sort?: string;
    direction?: 'asc' | 'desc';
  }) =>
    portalClient
      .get<PaginatedResponse<SupplierRfq>>('/b2b/supplier/rfqs', { params })
      .then((r) => r.data),

  show: (id: string) =>
    portalClient.get<{ data: SupplierRfq }>(`/b2b/supplier/rfqs/${id}`).then((r) => r.data.data),

  saveQuote: (id: string, data: SupplierQuoteWrite & { submit: boolean }) =>
    portalClient
      .put<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${id}/quote`, data)
      .then((r) => r.data.data),

  withdraw: (id: string) =>
    portalClient
      .post<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${id}/quote/withdraw`)
      .then((r) => r.data.data),

  uploadDocument: (id: string, form: FormData) =>
    portalClient
      .post<{
        data: { id: string; document_type: string; original_filename: string };
      }>(`/b2b/supplier/rfqs/${id}/documents`, form)
      .then((r) => r.data.data),

  downloadDocumentUrl: (rfqId: string, documentId: string) =>
    `/api/v1/b2b/supplier/rfqs/${rfqId}/documents/${documentId}/download`,
};
