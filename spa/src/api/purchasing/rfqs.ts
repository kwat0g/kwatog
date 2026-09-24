import { unwrappingClient as client } from '../client';
import { createPortalClient } from '../b2b/client';
import type { PaginatedResponse } from '@/types';
import type { PurchaseOrder, RequestForQuote, SupplierQuote } from '@/types/purchasing';

const { client: portalClient } = createPortalClient('supplier');

export interface CreateRfqData {
  title: string; instructions?: string; closes_at: string;
  invitations: Array<{ vendor_id: string; exception_reason?: string }>;
  specifications?: Record<string, string>; allow_partial_quantity?: Record<string, boolean>;
  required_delivery_dates?: Record<string, string>;
}

export interface SupplierQuoteDraftData {
  vat_inclusive?: boolean;
  vat_amount?: string;
  freight_amount?: string;
  other_charges?: string;
  quote_valid_until?: string;
  payment_terms?: string;
  notes?: string;
  items: Array<{
    request_for_quote_item_id: string;
    response_status: 'quoted' | 'no_quote';
    offered_quantity?: string;
    unit_price?: string;
    line_vat_amount?: string;
    line_freight_amount?: string;
    line_other_charges?: string;
    lead_time_days?: number;
    proposed_delivery_date?: string;
  }>;
}

export interface ManualRfqQuoteData extends SupplierQuoteDraftData {
  vendor_id: string;
  quotation_document_id: string;
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
  reviewQuality: (rfqId: string, quoteItemId: string, data: { compliance_status: 'compliant' | 'exception' | 'blocking'; compliance_notes?: string }) => client.patch(`/purchasing/rfqs/${rfqId}/quote-items/${quoteItemId}/quality-review`, data).then((r) => r.data),
  award: (id: string, awards: Array<{ request_for_quote_item_id: string; supplier_quote_item_id: string; awarded_quantity: string; award_reason: string; single_response_justification?: string }>) => client.post<{ data: RequestForQuote; purchase_orders: PurchaseOrder[] }>(`/purchasing/rfqs/${id}/award`, { awards }).then((r) => r.data),
  cancel: (id: string, reason: string) => client.post<RequestForQuote>(`/purchasing/rfqs/${id}/cancel`, { reason }).then((r) => r.data),
  purchaseOrders: (id: string) => client.get<PurchaseOrder[] | { data: PurchaseOrder[] }>(`/purchasing/rfqs/${id}/purchase-orders`).then((r) => Array.isArray(r.data) ? r.data : r.data.data),
  uploadDocument: (id: string, form: FormData) => client.post(`/purchasing/rfqs/${id}/documents`, form).then((r) => r.data),
  manualQuote: (id: string, data: ManualRfqQuoteData) => client.post<SupplierQuote>(`/purchasing/rfqs/${id}/quotes/manual`, data).then((r) => r.data),
};

export const supplierRfqsApi = {
  list: (params?: { page?: number; per_page?: number }) => portalClient.get<PaginatedResponse<RequestForQuote>>('/b2b/supplier/rfqs', { params }).then((r) => r.data),
  show: (id: string) => portalClient.get<{ data: RequestForQuote }>(`/b2b/supplier/rfqs/${id}`).then((r) => r.data.data),
  saveQuote: (id: string, data: SupplierQuoteDraftData) => portalClient.post<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${id}/quotes`, data).then((r) => r.data.data),
  updateQuote: (rfqId: string, quoteId: string, data: SupplierQuoteDraftData) => portalClient.put<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${rfqId}/quotes/${quoteId}`, data).then((r) => r.data.data),
  submit: (rfqId: string, quoteId: string) => portalClient.post<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${rfqId}/quotes/${quoteId}/submit`).then((r) => r.data.data),
  withdraw: (rfqId: string, quoteId: string, reason: string) => portalClient.post<{ data: SupplierQuote }>(`/b2b/supplier/rfqs/${rfqId}/quotes/${quoteId}/withdraw`, { reason }).then((r) => r.data.data),
  uploadDocument: (rfqId: string, quoteId: string, form: FormData) => { form.set('quote_id', quoteId); return portalClient.post(`/b2b/supplier/rfqs/${rfqId}/documents`, form).then((r) => r.data); },
  downloadDocumentUrl: (rfqId: string, documentId: string) => `/api/v1/b2b/supplier/rfqs/${rfqId}/documents/${documentId}/download`,
};
