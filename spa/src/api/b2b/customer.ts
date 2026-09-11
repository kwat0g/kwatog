import { createPortalClient, getPortalCsrf } from './client';
import type {
 CustomerPortalUser,
 CustomerDashboardData,
 PortalSoSummary,
 PortalSoDetail,
 PortalCatalogItem,
 PortalInvoiceSummary,
 PortalInvoiceDetail,
 PortalDeliverySummary,
 PortalDeliveryDetail,
 PortalComplaint,
 EightDReportData,
 StatementOfAccount,
 DeliverySchedule,
 DeliveryScheduleLine,
} from '@/types/b2b';
import type { ChainStep } from '@/types/chain';
import type { PaginatedResponse } from '@/types';
import type { BusinessPolicies } from '@/api/businessPolicies';

// Customer portal authentication is the same HTTP-only cookie session used by
// the internal SPA. There is deliberately no storage key or bearer-token
// setter on this client.
const { client: portalClient } = createPortalClient();

type CustomerLoginResponse = {
 user: CustomerPortalUser;
};

export const customerPortalApi = {
 // ── Auth ──────────────────────────────────────────
 login: async (email: string, password: string) => {
 await getPortalCsrf();
 const { data } = await portalClient.post<{ data: CustomerLoginResponse }>('/b2b/customer/login', { email, password });
 return data.data.user;
 },

 logout: async () => {
 try {
 await portalClient.post('/b2b/customer/logout');
 } finally { /* the server invalidates the HTTP-only session */ }
 },

 me: async () => {
 const { data } = await portalClient.get<{ data: CustomerPortalUser }>('/b2b/customer/me');
 return data.data;
 },

 forgotPassword: async (email: string) => {
 await getPortalCsrf();
 const { data } = await portalClient.post<{ message: string }>('/b2b/customer/forgot-password', { email });
 return data;
 },

 resetPassword: async (token: string, password: string, password_confirmation: string) => {
 await getPortalCsrf();
 const { data } = await portalClient.post<{ message: string }>('/b2b/customer/reset-password', {
 token,
 password,
 password_confirmation,
 });
 return data;
 },

 changePassword: async (payload: { current_password: string; new_password: string; new_password_confirmation: string }) => {
  const { data } = await portalClient.post<{ message: string }>('/b2b/customer/change-password', payload);
  return data;
 },

 // Shared read-only policy values, authenticated with the portal session.
 businessPolicies: async () => {
 // Portal-scoped endpoint: the customer session guard cannot read the
 // internal auth:sanctum /business-policies route.
 const { data } = await portalClient.get<{ data: BusinessPolicies }>('/b2b/customer/business-policies');
 return data.data;
 },

 // ── Dashboard ──────────────────────────────────────
 dashboard: async () => {
 const { data } = await portalClient.get<{ data: CustomerDashboardData }>('/b2b/customer/dashboard');
 return data.data;
 },

 // ── Sales Orders ───────────────────────────────────
 listCatalog: async (params?: { as_of?: string; search?: string }) => {
 const { data } = await portalClient.get<{ data: PortalCatalogItem[] }>('/b2b/customer/catalog', { params });
 return data.data;
 },

 listOrders: async (params?: { status?: string; search?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalSoSummary>>('/b2b/customer/orders', { params });
 return data;
 },

 createOrder: async (form: {
 date?: string;
 notes?: string;
 items: Array<{ product_id: string; quantity: string; delivery_date: string }>;
 }) => {
 const { data } = await portalClient.post<{ data: PortalSoDetail; message: string }>('/b2b/customer/orders', form);
 return data;
 },

 getOrder: async (id: string) => {
 const { data } = await portalClient.get<{ data: PortalSoDetail }>(`/b2b/customer/orders/${id}`);
 return data.data;
 },

 getOrderChain: async (id: string) => {
 const { data } = await portalClient.get<{ data: ChainStep[] }>(`/b2b/customer/orders/${id}/chain`);
 return data.data;
 },

 // ── Invoices ───────────────────────────────────────
 listInvoices: async (params?: { status?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalInvoiceSummary>>('/b2b/customer/invoices', { params });
 return data;
 },

 getInvoice: async (id: string) => {
 const { data } = await portalClient.get<{ data: PortalInvoiceDetail }>(`/b2b/customer/invoices/${id}`);
 return data.data;
 },

 downloadInvoicePdf: async (id: string) => {
 const { data } = await portalClient.get<Blob>(`/b2b/customer/invoices/${id}/pdf`, {
 responseType: 'blob',
 });
 return data;
 },

 // ── Deliveries ─────────────────────────────────────
 listDeliveries: async (params?: { status?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalDeliverySummary>>('/b2b/customer/deliveries', { params });
 return data;
 },

 getDelivery: async (id: string) => {
  const { data } = await portalClient.get<{ data: PortalDeliveryDetail }>(`/b2b/customer/deliveries/${id}`);
  return data.data;
 },

 confirmDelivery: async (id: string, form: { receiver_name?: string; receiver_position?: string; delivery_remarks?: string }) => {
  const { data } = await portalClient.post<{ data: PortalDeliveryDetail; message: string }>(`/b2b/customer/deliveries/${id}/confirm`, form);
  return data;
 },

 viewDeliveryProof: async (deliveryId: string, proofId: string) => {
 const { data } = await portalClient.get<Blob>(
 `/b2b/customer/deliveries/${deliveryId}/proofs/${proofId}/view`,
 { responseType: 'blob' }
 );
 return data;
 },

 // ── Complaints (RMA / Customer Complaints) ─────────
 complaintOptions: async () => {
 const { data } = await portalClient.get<{ data: {
 severities: Array<{ value: string; label: string }>;
 statuses: Array<{ value: string; label: string }>;
 } }>('/b2b/customer/complaints/options');
 return data.data;
 },
 listComplaints: async (params?: {
 status?: string;
 search?: string;
 date_from?: string;
 date_to?: string;
 page?: number;
 per_page?: number;
 }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalComplaint>>('/b2b/customer/complaints', { params });
 return data;
 },

 createComplaint: async (form: {
  order_id?: string;
  severity: string;
 description: string;
 affected_quantity: number;
 }) => {
 const { data } = await portalClient.post<{ data: PortalComplaint; message: string }>('/b2b/customer/complaints', form);
 return data;
 },

 // ── 8D Report ──────────────────────────────────────
 get8dReport: async (complaintId: string) => {
 const { data } = await portalClient.get<{ data: EightDReportData }>(`/b2b/customer/complaints/${complaintId}/8d-report`);
 return data.data;
 },

 // ── Statement of Account ──────────────────────────
 getStatementOfAccount: async () => {
 const { data } = await portalClient.get<{ data: StatementOfAccount }>('/b2b/customer/statement-of-account');
 return data.data;
 },

 // ── Delivery Schedules ────────────────────────────
 listDeliverySchedules: async (params?: { page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<DeliverySchedule>>('/b2b/customer/delivery-schedules', { params });
 return data;
 },

 createDeliverySchedule: async (form: {
 month: string;
 lines: DeliveryScheduleLine[];
 }) => {
 const { data } = await portalClient.post<{ data: DeliverySchedule; message: string }>('/b2b/customer/delivery-schedules', form);
 return data;
 },
};
