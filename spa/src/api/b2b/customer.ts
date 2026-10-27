import { createPortalClient, getPortalCsrf } from './client';
import type {
 CustomerPortalUser,
 CustomerDashboardData,
 PortalSoSummary,
 PortalSoDetail,
 PortalCatalogItem,
 PortalOrderQuote,
 PortalInvoiceSummary,
 PortalInvoiceDetail,
 PortalDeliverySummary,
 PortalDeliveryDetail,
 PortalReceiptGroup,
 PortalReceiptGroupSubmission,
 PortalComplaint,
 EightDReportData,
 StatementOfAccount,
 DeliverySchedule,
 DeliveryScheduleLine,
 PortalReturnRequest,
 PortalReturnSourceOptions,
} from '@/types/b2b';
import type { PriceRequest } from '@/types/pricing';
import type { ChainStep } from '@/types/chain';
import type { PaginatedResponse } from '@/types';
import type { BusinessPolicies } from '@/api/businessPolicies';

// Customer portal authentication is the same HTTP-only cookie session used by
// the internal SPA. There is deliberately no storage key or bearer-token
// setter on this client.
const { client: portalClient } = createPortalClient('customer');

type CustomerLoginResponse = {
 user: CustomerPortalUser;
};

export const customerPortalApi = {
  priceRequests: (page = 1, request?: string) => portalClient.get<PaginatedResponse<PriceRequest>>('/b2b/customer/price-requests', { params: { page, request } }).then(r => r.data),
  requestPrice: (data: { product_id: string; quantity: number; requested_price?: string; notes: string }) => portalClient.post('/b2b/customer/price-requests', data),
  respondPrice: (id: string, decision: 'accept' | 'reject', version: number) => portalClient.post(`/b2b/customer/price-requests/${id}/respond`, { decision, version }),
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
 listCatalog: async (params?: { as_of?: string; search?: string }, signal?: AbortSignal) => {
 const { data } = await portalClient.get<{ data: PortalCatalogItem[] }>('/b2b/customer/catalog', { params, signal });
 return data.data;
 },

 listOrders: async (params?: { status?: string; search?: string; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalSoSummary>>('/b2b/customer/orders', { params });
 return data;
 },

 quoteOrder: async (form: { sales_order_id?: string; items: Array<{ product_id: string; quantity: string; delivery_date: string }> }) => {
  const { data } = await portalClient.post<{ data: PortalOrderQuote }>('/b2b/customer/orders/quote', form);
  return data.data;
 },

  createOrder: async (form: {
  quote_signature?: string;
  date?: string;
  notes?: string;
  items: Array<{ product_id: string; quantity: string; delivery_date: string }>;
  }, idempotencyKey: string) => {
  const { data } = await portalClient.post<{ data: PortalSoDetail; message: string }>('/b2b/customer/orders', form, {
   headers: { 'Idempotency-Key': idempotencyKey },
  });
 return data;
 },

 updateOrder: async (id: string, form: {
  quote_signature?: string;
  notes?: string;
  items: Array<{ product_id: string; quantity: string; delivery_date: string }>;
 }) => {
  const { data } = await portalClient.put<{ data: PortalSoDetail; message?: string }>(`/b2b/customer/orders/${id}`, form);
  return data;
 },

 cancelOrder: async (id: string, reason?: string) => {
  const { data } = await portalClient.post<{
   data: PortalSoDetail;
   outcome: 'cancelled' | 'requested';
   message: string;
  }>(`/b2b/customer/orders/${id}/cancel`, { reason });
  return data;
 },

 respondToDeliveryProposal: async (id: string, responseId: string, decision: 'accept' | 'reject') => {
  const { data } = await portalClient.post<{ data: unknown }>(`/b2b/customer/orders/${id}/delivery-proposals/${responseId}/respond`, { decision });
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

  respondToOrder: async (id: string, form: {
   type: 'accept' | 'propose' | 'decline';
   proposed_delivery_date?: string;
   notes?: string;
   items?: Array<{ sales_order_item_id: string; proposed_quantity: string; proposed_unit_price: string; reason?: string }>;
  }) => {
   const { data } = await portalClient.post<{ data: unknown; message: string }>(`/b2b/customer/orders/${id}/respond`, form);
   return data;
  },

 // ── Invoices ───────────────────────────────────────
 invoiceSignals: async () => {
 const { data } = await portalClient.get<{ data: { unpaid: number; overdue: number } }>('/b2b/customer/badges');
 return data.data;
 },
 listInvoices: async (params?: { status?: string; signal?: 'unpaid' | 'overdue'; page?: number; per_page?: number }) => {
 const { data } = await portalClient.get<PaginatedResponse<PortalInvoiceSummary>>('/b2b/customer/invoices', { params });
 return data;
 },

 getInvoice: async (id: string) => {
 const { data } = await portalClient.get<{ data: PortalInvoiceDetail }>(`/b2b/customer/invoices/${id}`);
 return data.data;
 },

 submitInvoicePayment: async (id: string, form: FormData) => {
   const { data } = await portalClient.post<{ data: import('@/types/invoice-payments').InvoicePaymentSubmission }>(`/b2b/customer/invoices/${id}/payment-submissions`, form);
   return data.data;
 },
 downloadInvoicePaymentProof: async (id: string, submissionId: string) => {
   const { data } = await portalClient.get<Blob>(`/b2b/customer/invoices/${id}/payment-submissions/${submissionId}/proof`, { responseType: 'blob' });
   return data;
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

 confirmDelivery: async (id: string, form: { receiver_name: string; receiver_position?: string; delivery_remarks?: string; lines: Array<{ delivery_item_id: string; received_quantity: string }> }) => {
  const { data } = await portalClient.post<{ data: PortalDeliveryDetail; message: string }>(`/b2b/customer/deliveries/${id}/confirm`, form);
  return data;
 },

 getReceiptGroup: async (id: string) => {
  const { data } = await portalClient.get<{ data: PortalReceiptGroup | null }>(`/b2b/customer/deliveries/${id}/receipt-group`);
  return data.data;
 },

 confirmReceiptGroup: async (id: string, form: PortalReceiptGroupSubmission) => {
  const { data } = await portalClient.post<{ data: PortalReceiptGroup; message: string }>(`/b2b/customer/deliveries/${id}/confirm-group`, form);
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

  // ── Customer returns (RMA) ─────────────────────────
  returnSourceOptions: async () => {
   const { data } = await portalClient.get<{ data: PortalReturnSourceOptions }>('/b2b/customer/return-requests/source-options');
   return data.data;
  },

  listReturnRequests: async (params?: { page?: number; per_page?: number }) => {
   const { data } = await portalClient.get<PaginatedResponse<PortalReturnRequest>>('/b2b/customer/return-requests', { params });
   return data;
  },

  getReturnRequest: async (id: string) => {
   const { data } = await portalClient.get<{ data: PortalReturnRequest }>(`/b2b/customer/return-requests/${id}`);
   return data.data;
  },

  createReturnRequest: async (form: {
   reason_code?: string;
   reason_description?: string;
   customer_notes?: string;
   items: Array<{
    quantity: string;
    reason?: string;
    condition?: string;
    source_invoice_item_id?: string;
    source_sales_order_item_id?: string;
    source_delivery_item_id?: string;
   }>;
  }) => {
   const { data } = await portalClient.post<{ data: PortalReturnRequest; message: string }>('/b2b/customer/return-requests', form);
   return data;
  },
};
