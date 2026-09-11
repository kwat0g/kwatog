import { createPortalClient, getPortalCsrf } from './client';
import type {
 SupplierPortalUser,
 SupplierDashboardData,
 PortalPoSummary,
 PortalPoDetail,
 SupplierBillSummary,
 SupplierBillDetail,
 SupplierDeliverySummary,
 PortalShippingDocument,
 SubmittedBill,
 VendorStatementOfAccount,
 DeliverySchedule,
 PortalItemCatalogEntry,
 PortalSupplierListing,
 PortalSupplierListingInput,
 PortalBulkListingResult,
 RespondToPurchaseOrderPayload,
} from '@/types/b2b';
import type { PaginatedResponse } from '@/types';
import type { BusinessPolicies } from '@/api/businessPolicies';

// Supplier portal authentication is the same HTTP-only cookie session used by
// the internal SPA. There is deliberately no storage key or bearer-token
// setter on this client.
const { client: portalClient } = createPortalClient();

type SupplierLoginResponse = {
  user: SupplierPortalUser;
};

export const supplierPortalApi = {
  // ── Auth ──────────────────────────────────────────
  login: async (email: string, password: string) => {
  await getPortalCsrf();
  const { data } = await portalClient.post<{ data: SupplierLoginResponse }>('/b2b/supplier/login', { email, password });
  return data.data.user;
  },

  logout: async () => {
  try {
  await portalClient.post('/b2b/supplier/logout');
  } finally { /* the server invalidates the HTTP-only session */ }
  },

 me: async () => {
 const { data } = await portalClient.get<{ data: SupplierPortalUser }>('/b2b/supplier/me');
 return data.data;
 },

 forgotPassword: async (email: string) => {
 await getPortalCsrf();
 const { data } = await portalClient.post<{ message: string }>('/b2b/supplier/forgot-password', { email });
 return data;
 },

 resetPassword: async (token: string, password: string, password_confirmation: string) => {
 await getPortalCsrf();
 const { data } = await portalClient.post<{ message: string }>('/b2b/supplier/reset-password', {
 token,
 password,
 password_confirmation,
 });
 return data;
 },

 changePassword: async (payload: { current_password: string; new_password: string; new_password_confirmation: string }) => {
  const { data } = await portalClient.post<{ message: string }>('/b2b/supplier/change-password', payload);
  return data;
 },

  // Shared read-only policy values, authenticated with the portal session.
  businessPolicies: async () => {
  // Portal-scoped endpoint: the supplier session guard cannot read the
  // internal auth:sanctum /business-policies route.
  const { data } = await portalClient.get<{ data: BusinessPolicies }>('/b2b/supplier/business-policies');
  return data.data;
  },

 // ── Dashboard ──────────────────────────────────────
 dashboard: async () => {
 const { data } = await portalClient.get<{ data: SupplierDashboardData }>('/b2b/supplier/dashboard');
 return data.data;
 },

 // ── Purchase Orders ────────────────────────────────
  listPos: async (params?: { status?: string; search?: string; page?: number; per_page?: number; sort?: string; dir?: 'asc' | 'desc' }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalPoSummary>>('/b2b/supplier/purchase-orders', { params });
 return data;
 },

 getPo: async (id: string) => {
 const { data } = await portalClient.get<{ data: PortalPoDetail }>(`/b2b/supplier/purchase-orders/${id}`);
 return data.data;
 },

 acknowledgePo: async (id: string) => {
 const { data } = await portalClient.post<{ message: string }>(`/b2b/supplier/purchase-orders/${id}/acknowledge`);
 return data;
 },

 /** Accept, counter-propose or decline a PO. Returns the updated supplier PO. */
 respondToPurchaseOrder: async (id: string, payload: RespondToPurchaseOrderPayload) => {
 const { data } = await portalClient.post<{ data: PortalPoDetail }>(`/b2b/supplier/purchase-orders/${id}/respond`, payload);
 return data.data;
 },

 // ── Shipments ──────────────────────────────────────
 updateShipment: async (poId: string, form: { shipped_date?: string; carrier?: string; tracking_number?: string; estimated_arrival?: string; notes?: string }) => {
 const { data } = await portalClient.post<{ message: string }>(`/b2b/supplier/purchase-orders/${poId}/shipment-update`, form);
 return data;
 },

 // ── Invoices ───────────────────────────────────────
 listInvoices: async (params?: { status?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<SupplierBillSummary>>('/b2b/supplier/invoices', { params });
 return data;
 },

 getInvoice: async (id: string) => {
 const { data } = await portalClient.get<{ data: SupplierBillDetail }>(`/b2b/supplier/invoices/${id}`);
 return data.data;
 },

 // ── Deliveries ─────────────────────────────────────
 listDeliveries: async (params?: { status?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<SupplierDeliverySummary>>('/b2b/supplier/deliveries', { params });
 return data;
 },

 // ── Statement of Account ────────────────────────────
 statementOfAccount: async () => {
 const { data } = await portalClient.get<{ data: VendorStatementOfAccount }>('/b2b/supplier/statement-of-account');
 return data.data;
 },

 // ── Delivery Schedules ──────────────────────────────
 listDeliverySchedules: async (params?: { page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<DeliverySchedule>>('/b2b/supplier/delivery-schedules', { params });
 return data;
 },

 createDeliverySchedule: async (form: {
 purchase_order_id: string;
 month: string;
 lines: Array<{ purchase_order_item_id: string; product_name?: string; quantity: number; notes?: string }>;
 }) => {
 const { data } = await portalClient.post<{ data: DeliverySchedule; message: string }>('/b2b/supplier/delivery-schedules', form);
 return data;
 },

 // ── PDF Downloads ───────────────────────────────────
 downloadPoPdf: async (id: string) => {
 const { data } = await portalClient.get<Blob>(`/b2b/supplier/purchase-orders/${id}/pdf`, {
 responseType: 'blob',
 });
 return data;
 },

 downloadInvoicePdf: async (id: string) => {
 const { data } = await portalClient.get<Blob>(`/b2b/supplier/invoices/${id}/pdf`, {
 responseType: 'blob',
 });
 return data;
 },

 // ── Shipping Documents ──────────────────────────────
 shippingDocumentOptions: async () => {
 const { data } = await portalClient.get<{ data: { document_types: Array<{ value: string; label: string }> } }>('/b2b/supplier/purchase-orders/shipping-documents/options');
 return data.data;
 },
 listShippingDocuments: async (poId: string) => {
 const { data } = await portalClient.get<{ data: PortalShippingDocument[] }>(`/b2b/supplier/purchase-orders/${poId}/shipping-documents`);
 return data.data;
 },

 uploadShippingDocument: async (poId: string, form: FormData) => {
 const { data } = await portalClient.post<{ data: PortalShippingDocument; message: string }>(
 `/b2b/supplier/purchase-orders/${poId}/shipping-documents`,
 form
 );
 return data;
 },

 downloadShippingDocument: async (id: string) => {
 const { data } = await portalClient.get<Blob>(
 `/b2b/supplier/shipping-documents/${id}/download`,
 { responseType: 'blob' }
 );
 return data;
 },

 // ── Invoice Submission (Supplier → Draft Bill) ─────
 submitInvoice: async (poId: string, form: FormData) => {
 const { data } = await portalClient.post<{ data: SubmittedBill; message: string }>(
 `/b2b/supplier/purchase-orders/${poId}/submit-invoice`,
 form
 );
 return data;
 },

 // ── Item Catalog + Supplier Item Listings ─────────
 itemCatalog: async (params?: { search?: string; item_type?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalItemCatalogEntry>>('/b2b/supplier/item-catalog', { params });
 return data;
 },

 listItemListings: async (params?: { page?: number; per_page?: number; status?: string }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalSupplierListing>>('/b2b/supplier/item-listings', { params });
 return data;
 },

 createItemListing: async (form: PortalSupplierListingInput) => {
 const { data } = await portalClient.post<{ data: PortalSupplierListing }>('/b2b/supplier/item-listings', form);
 return data.data;
 },

 bulkCreateItemListings: async (items: PortalSupplierListingInput[]) => {
 const { data } = await portalClient.post<{ data: PortalBulkListingResult }>('/b2b/supplier/item-listings/bulk', { items });
 return data.data;
 },

 updateItemListing: async (id: string, form: PortalSupplierListingInput) => {
 const { data } = await portalClient.put<{ data: PortalSupplierListing }>(`/b2b/supplier/item-listings/${id}`, form);
 return data.data;
 },

};
