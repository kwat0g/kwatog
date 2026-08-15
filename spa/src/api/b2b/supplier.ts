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
} from '@/types/b2b';
import type { BusinessPolicies } from '@/api/businessPolicies';

const { client: portalClient, setToken } = createPortalClient('ogami_supplier_portal_token');

type SupplierLoginResponse = {
 token: string;
 user: SupplierPortalUser;
};

export const supplierPortalApi = {
 // ── Auth ──────────────────────────────────────────
 login: async (email: string, password: string) => {
 await getPortalCsrf();
 const { data } = await portalClient.post<{ data: SupplierLoginResponse }>('/b2b/supplier/login', { email, password });
 setToken(data.data.token);
 return data.data.user;
 },

 logout: async () => {
 try {
 await portalClient.post('/b2b/supplier/logout');
 } finally {
 setToken(null);
 }
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

 // Shared read-only policy values, authenticated with the portal bearer token.
 businessPolicies: async () => {
 const { data } = await portalClient.get<{ data: BusinessPolicies }>('/business-policies');
 return data.data;
 },

 // ── Dashboard ──────────────────────────────────────
 dashboard: async () => {
 const { data } = await portalClient.get<{ data: SupplierDashboardData }>('/b2b/supplier/dashboard');
 return data.data;
 },

 // ── Purchase Orders ────────────────────────────────
 listPos: async (params?: { status?: string; page?: number }) => {
 const { data } = await portalClient.get<{ data: PortalPoSummary[] }>('/b2b/supplier/purchase-orders', { params });
 return data.data;
 },

 getPo: async (id: string) => {
 const { data } = await portalClient.get<{ data: PortalPoDetail }>(`/b2b/supplier/purchase-orders/${id}`);
 return data.data;
 },

 acknowledgePo: async (id: string) => {
 const { data } = await portalClient.post<{ message: string }>(`/b2b/supplier/purchase-orders/${id}/acknowledge`);
 return data;
 },

 // ── Shipments ──────────────────────────────────────
 updateShipment: async (poId: string, form: { tracking_number?: string; estimated_arrival?: string; notes?: string }) => {
 const { data } = await portalClient.post<{ message: string }>(`/b2b/supplier/purchase-orders/${poId}/shipment-update`, form);
 return data;
 },

 // ── Invoices ───────────────────────────────────────
 listInvoices: async (params?: { status?: string; page?: number }) => {
 const { data } = await portalClient.get<{ data: SupplierBillSummary[] }>('/b2b/supplier/invoices', { params });
 return data.data;
 },

 getInvoice: async (id: string) => {
 const { data } = await portalClient.get<{ data: SupplierBillDetail }>(`/b2b/supplier/invoices/${id}`);
 return data.data;
 },

 // ── Deliveries ─────────────────────────────────────
 listDeliveries: async () => {
 const { data } = await portalClient.get<{ data: SupplierDeliverySummary[] }>('/b2b/supplier/deliveries');
 return data.data;
 },

 // ── Statement of Account ────────────────────────────
 statementOfAccount: async () => {
 const { data } = await portalClient.get<{ data: VendorStatementOfAccount }>('/b2b/supplier/statement-of-account');
 return data.data;
 },

 // ── Delivery Schedules ──────────────────────────────
 listDeliverySchedules: async () => {
 const { data } = await portalClient.get<{ data: DeliverySchedule[] }>('/b2b/supplier/delivery-schedules');
 return data.data;
 },

 createDeliverySchedule: async (form: {
 purchase_order_id: string;
 month: string;
 lines: Array<{ product_name: string; quantity: number; notes?: string }>;
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
 form,
 { headers: { 'Content-Type': 'multipart/form-data' } }
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
 form,
 { headers: { 'Content-Type': 'multipart/form-data' } }
 );
 return data;
 },

};
